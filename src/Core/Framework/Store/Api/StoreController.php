<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Store\Api;

use GuzzleHttp\Exception\ClientException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Context\AdminApiSource;
use Shopware\Core\Framework\Context\Exception\InvalidContextSourceException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;
use Shopware\Core\Framework\Plugin\PluginManagementService;
use Shopware\Core\Framework\Store\Exception\CanNotDownloadPluginManagedByComposerException;
use Shopware\Core\Framework\Store\Exception\StoreApiException;
use Shopware\Core\Framework\Store\Exception\StoreInvalidCredentialsException;
use Shopware\Core\Framework\Store\Exception\StoreNotAvailableException;
use Shopware\Core\Framework\Store\Exception\StoreTokenMissingException;
use Shopware\Core\Framework\Store\Services\StoreClient;
use Shopware\Core\Framework\Validation\DataBag\QueryDataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\User\UserEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StoreController extends AbstractController
{
    /**
     * @var StoreClient
     */
    private $storeClient;

    /**
     * @var EntityRepositoryInterface
     */
    private $pluginRepo;

    /**
     * @var PluginManagementService
     */
    private $pluginManagementService;

    /**
     * @var PluginLifecycleService
     */
    private $pluginLifecycleService;

    /**
     * @var EntityRepositoryInterface
     */
    private $userRepository;

    /**
     * @var SystemConfigService
     */
    private $configService;

    public function __construct(
        StoreClient $storeClient,
        EntityRepositoryInterface $pluginRepo,
        PluginManagementService $pluginManagementService,
        PluginLifecycleService $pluginLifecycleService,
        EntityRepositoryInterface $userRepository,
        SystemConfigService $configService
    ) {
        $this->storeClient = $storeClient;
        $this->pluginRepo = $pluginRepo;
        $this->pluginManagementService = $pluginManagementService;
        $this->pluginLifecycleService = $pluginLifecycleService;
        $this->userRepository = $userRepository;
        $this->configService = $configService;
    }

    /**
     * @Route("/api/v{version}/_action/store/ping", name="api.custom.store.ping", methods={"GET"})
     */
    public function pingStoreAPI(): Response
    {
        try {
            $this->storeClient->ping();
        } catch (ClientException $exception) {
            throw new StoreNotAvailableException();
        }

        return new Response();
    }

    /**
     * @Route("/api/v{version}/_action/store/login", name="api.custom.store.login", methods={"POST"})
     */
    public function login(RequestDataBag $requestDataBag, QueryDataBag $queryDataBag, Context $context): JsonResponse
    {
        $shopwareId = $requestDataBag->get('shopwareId');
        $password = $requestDataBag->get('password');
        $language = $queryDataBag->get('language', '');

        if ($shopwareId === null || $password === null) {
            throw new StoreInvalidCredentialsException();
        }

        if (!$context->getSource() instanceof AdminApiSource) {
            throw new InvalidContextSourceException(AdminApiSource::class, \get_class($context->getSource()));
        }

        try {
            $accessTokenStruct = $this->storeClient->loginWithShopwareId($shopwareId, $password, $language, $context);
        } catch (ClientException $exception) {
            throw new StoreApiException($exception);
        }

        $this->configService->set('core.store.shopSecret', $accessTokenStruct->getShopSecret());
        $this->configService->set('core.store.shopwareId', $shopwareId);

        $userId = $context->getSource()->getUserId();
        $this->userRepository->update([
            ['id' => $userId, 'storeToken' => $accessTokenStruct->getShopUserToken()->getToken()],
        ], $context);

        return new JsonResponse();
    }

    /**
     * @Route("/api/v{version}/_action/store/checklogin", name="api.custom.store.checklogin", methods={"POST"})
     */
    public function checkLogin(Context $context): Response
    {
        if (!$context->getSource() instanceof AdminApiSource) {
            throw new InvalidContextSourceException(AdminApiSource::class, \get_class($context->getSource()));
        }

        $userId = $context->getSource()->getUserId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $userId));

        /** @var UserEntity|null $user */
        $user = $this->userRepository->search($criteria, $context)->getEntities()->first();

        if ($user && $user->getStoreToken()) {
            return new Response();
        }
        throw new StoreTokenMissingException();
    }

    /**
     * @Route("/api/v{version}/_action/store/logout", name="api.custom.store.logout", methods={"POST"})
     */
    public function logout(Context $context): Response
    {
        if (!$context->getSource() instanceof AdminApiSource) {
            throw new InvalidContextSourceException(AdminApiSource::class, \get_class($context->getSource()));
        }

        $userId = $context->getSource()->getUserId();
        $this->userRepository->update([
            ['id' => $userId, 'storeToken' => null],
        ], $context);

        return new Response();
    }

    /**
     * @Route("/api/v{version}/_action/store/licenses", name="api.custom.store.licenses", methods={"GET"})
     */
    public function getLicenseList(QueryDataBag $queryDataBag, Context $context): JsonResponse
    {
        $storeToken = $this->getUserStoreToken($context);
        $language = $queryDataBag->get('language', '');

        try {
            $licenseList = $this->storeClient->getLicenseList($storeToken, $language, $context);
        } catch (ClientException $exception) {
            throw new StoreApiException($exception);
        }

        return new JsonResponse([
            'items' => $licenseList,
            'total' => count($licenseList),
        ]);
    }

    /**
     * @Route("/api/v{version}/_action/store/updates", name="api.custom.store.updates", methods={"GET"})
     */
    public function getUpdateList(Request $request, Context $context): JsonResponse
    {
        $language = $request->query->get('language', '');

        /** @var PluginCollection $plugins */
        $plugins = $this->pluginRepo->search(new Criteria(), $context)->getEntities();
        try {
            $storeToken = $this->getUserStoreToken($context);
        } catch (StoreTokenMissingException $e) {
            $storeToken = null;
        }

        try {
            $updatesList = $this->storeClient->getUpdatesList($storeToken, $plugins, $language, $request->getHost(), $context);
        } catch (ClientException $exception) {
            throw new StoreApiException($exception);
        }

        return new JsonResponse([
            'items' => $updatesList,
            'total' => count($updatesList),
        ]);
    }

    /**
     * @Route("/api/v{version}/_action/store/download", name="api.custom.store.download", methods={"GET"})
     */
    public function downloadPlugin(QueryDataBag $queryDataBag, Context $context): JsonResponse
    {
        $pluginName = $queryDataBag->get('pluginName');
        $language = $queryDataBag->get('language', '');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('plugin.name', $pluginName));

        /** @var PluginEntity|null $plugin */
        $plugin = $this->pluginRepo->search($criteria, $context)->first();

        if ($plugin !== null && $plugin->getManagedByComposer()) {
            throw new CanNotDownloadPluginManagedByComposerException('can not downloads plugins managed by composer from store api');
        }

        $unauthenticated = $queryDataBag->has('unauthenticated');
        if ($unauthenticated) {
            $storeToken = '';
        } else {
            $storeToken = $this->getUserStoreToken($context);
        }

        try {
            $data = $this->storeClient->getDownloadDataForPlugin($pluginName, $storeToken, $language, !$unauthenticated);
        } catch (ClientException $exception) {
            throw new StoreApiException($exception);
        }

        $statusCode = $this->pluginManagementService->downloadStorePlugin($data->getLocation(), $context);
        if ($statusCode !== Response::HTTP_OK) {
            return new JsonResponse(null, $statusCode);
        }

        /** @var PluginEntity|null $plugin */
        $plugin = $this->pluginRepo->search($criteria, $context)->first();
        if ($plugin && $plugin->getUpgradeVersion()) {
            $this->pluginLifecycleService->updatePlugin($plugin, $context);
        }

        return new JsonResponse();
    }

    private function getUserStoreToken(Context $context): string
    {
        if (!$context->getSource() instanceof AdminApiSource) {
            throw new InvalidContextSourceException(AdminApiSource::class, \get_class($context->getSource()));
        }

        $userId = $context->getSource()->getUserId();

        /** @var UserEntity|null $user */
        $user = $this->userRepository->search(new Criteria([$userId]), $context)->first();

        if ($user->getStoreToken() === null) {
            throw new StoreTokenMissingException();
        }

        return $user->getStoreToken();
    }
}

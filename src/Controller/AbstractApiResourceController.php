<?php

/*
 * (c) hessnatur Textilien GmbH <https://hessnatur.io/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hessnatur\SimpleRestCRUDBundle\Controller;

use Doctrine\ORM\QueryBuilder;
use Exception;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Request\ParamFetcher;
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandlerInterface;
use Hessnatur\SimpleRestCRUDBundle\Event\ApiResourceEvent;
use Hessnatur\SimpleRestCRUDBundle\HessnaturSimpleRestCRUDEvents;
use Hessnatur\SimpleRestCRUDBundle\Manager\ApiResourceManagerInterface;
use Hessnatur\SimpleRestCRUDBundle\Model\ApiResource;
use Hessnatur\SimpleRestCRUDBundle\Repository\ApiResourceRepositoryInterface;
use Lexik\Bundle\FormFilterBundle\Filter\FilterBuilderUpdaterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @author Felix Niedballa <felix.niedballa@hess-natur.de>
 */
abstract class AbstractApiResourceController extends AbstractFOSRestController
{
    /**
     * @var ApiResourceManagerInterface
     */
    protected $apiResourceManager;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var FormFactoryInterface
     */
    protected $formFactory;

    /**
     * @var FilterBuilderUpdaterInterface
     */
    protected $filterBuilderUpdater;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var ViewHandlerInterface
     */
    protected $viewHandler;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ApiResourceManagerInterface   $apiResourceManager
     * @param EventDispatcherInterface      $eventDispatcher
     * @param FormFactoryInterface          $formFactory
     * @param FilterBuilderUpdaterInterface $filterBuilderUpdater
     * @param RequestStack                  $requestStack
     * @param ViewHandlerInterface          $viewHandler
     * @param LoggerInterface               $logger
     */
    public function __construct(
        ApiResourceManagerInterface $apiResourceManager,
        EventDispatcherInterface $eventDispatcher,
        FormFactoryInterface $formFactory,
        FilterBuilderUpdaterInterface $filterBuilderUpdater,
        RequestStack $requestStack,
        ViewHandlerInterface $viewHandler,
        LoggerInterface $logger
    ) {
        $this->apiResourceManager = $apiResourceManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->formFactory = $formFactory;
        $this->filterBuilderUpdater = $filterBuilderUpdater;
        $this->requestStack = $requestStack;
        $this->viewHandler = $viewHandler;
        $this->logger = $logger;
    }

    /**
     * The function returns the class name of entity handled in this controller.
     *
     * @return string
     */
    abstract public function getApiResourceClass(): string;

    /**
     * The function returns the class name of the filter class.
     *
     * @return string
     */
    abstract public function getApiResourceFilterFormClass(): string;

    /**
     * The function returns the class name of the the filter class to update the entity handled in this controller.
     *
     * @return string
     */
    abstract public function getApiResourceFormClass(): string;

    /**
     * @return int
     */
    public function getApiResourceListLimit(): int
    {
        return 20;
    }

    /**
     * @return View
     *
     * @Rest\Get("")
     * @Rest\View(serializerGroups={"list"})
     */
    public function getApiResourcesAction()
    {
        $queryBuilder = $this->createQueryBuilder();
        $form = $this->formFactory->createNamed(null, $this->getApiResourceFilterFormClass());
        $form->submit($this->requestStack->getCurrentRequest()->query->all());

        $orderByField = $this->requestStack->getMasterRequest()->query->get(
            'orderBy',
            $this->getRepository()::getStandardSortField()
        );
        $orderByDirection = $this->requestStack->getMasterRequest()->query->get(
            'order',
            $this->getRepository()::getStandardSortDirection()
        );

        if (
            in_array($orderByField, $this->getRepository()::getSortableFields())
            && in_array(strtolower($orderByDirection), ['asc', 'desc'])
        ) {
            $queryBuilder->orderBy($queryBuilder->getRootAliases()[0].'.'.$orderByField, $orderByDirection);
        }

        $this->filterBuilderUpdater->addFilterConditions($form, $queryBuilder);

        return View::create($this->paginate($queryBuilder));
    }

    /**
     * @Rest\Get("/new")
     * @param ParamFetcher $paramFetcher
     * @param Request      $request
     *
     * @return Response
     * @throws Exception
     */
    public function getNewApiResourceForm(ParamFetcher $paramFetcher, Request $request)
    {
        $router = $this->get("router");
        $route = $router->match($request->getPathInfo());

        //$resource = new $this->resource;
        $filter = $this->formFactory->createNamed(null, $this->getApiResourceFilterFormClass());
        $filter->submit($this->requestStack->getCurrentRequest()->query->all());

        $form = $this->formFactory->createNamed(
            null,
            $this->getApiResourceFormClass(),
            $filter->getData(),
            [
                'method' => 'POST',
                'action' => $this->generateUrl(
                    str_replace('_getnewapiresourceform', '_postapiresource', $route['_route']),
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ]
        );

        return View::create($form->createView())
            ->setTemplate('@HessnaturSimpleRestCRUD/ApiResource/form.html.twig')
            ->setTemplateData(['form' => $form->createView()]);
    }

    /**
     * @Rest\Get("/{id}/edit")
     * @param ParamFetcher $paramFetcher
     * @param Request      $request
     *
     * @return Response
     * @throws Exception
     */
    public function getEditApiResourceForm(ParamFetcher $paramFetcher, Request $request, $id)
    {
        $router = $this->get("router");
        $route = $router->match($request->getPathInfo());

        $form = $this->formFactory->createNamed(
            null,
            $this->getApiResourceFormClass(),
            $this->fetchApiResource($id),
            [
                'method' => 'PUT',
                'action' => $this->generateUrl(
                    str_replace('_geteditapiresourceform', '_putapiresource', $route['_route']),
                    ['id' => $id],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ]
        );

        return View::create($form->createView())
            ->setTemplate('@HessnaturSimpleRestCRUD/ApiResource/form.html.twig')
            ->setTemplateData(['form' => $form->createView()]);
    }

    /**
     * @param string $id
     *
     * @return View
     *
     * @Rest\Get("/{id}")
     * @Rest\View(serializerGroups={"detail"})
     */
    public function getApiResourceAction(string $id)
    {
        return View::create($this->fetchApiResource($id));
    }

    /**
     * @param string $id
     *
     * @return View
     *
     * @Rest\Delete("/{id}")
     * @Rest\View(serializerGroups={"detail"})
     */
    public function deleteApiResourceAction(string $id)
    {
        $apiResource = $this->fetchApiResource($id);
        if (!$apiResource->getUserCanDelete()) {
            throw new AccessDeniedHttpException();
        }

        $this->eventDispatcher->dispatch(
            new ApiResourceEvent($apiResource),
            HessnaturSimpleRestCRUDEvents::BEFORE_DELETE_API_RESOURCE
        );

        $this->apiResourceManager->remove($apiResource);

        $this->eventDispatcher->dispatch(
            new ApiResourceEvent($apiResource),
            HessnaturSimpleRestCRUDEvents::AFTER_DELETE_API_RESOURCE
        );

        /**
         * No Content given
         * @see https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
         * @see https://en.wikipedia.org/wiki/List_of_HTTP_status_codes#2xx_Success
         */

        return View::create(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param string $id
     *
     * @return View
     *
     * @throws \Exception
     *
     * @Rest\Put("/{id}")
     * @Rest\View(serializerGroups={"detail"})
     */
    public function putApiResourceAction(string $id)
    {
        $apiResource = $this->fetchApiResource($id);
        if (!$apiResource->getUserCanUpdate()) {
            throw new AccessDeniedHttpException();
        }

        return $this->postApiResourceAction($apiResource);
    }

    /**
     * @param ApiResource|null $apiResource
     *
     * @return View
     *
     * @throws \Exception
     *
     * @Rest\Post("")
     * @Rest\View(serializerGroups={"detail"})
     */
    public function postApiResourceAction(?ApiResource $apiResource)
    {
        $responseCode = Response::HTTP_OK;
        if ($apiResource === null) {
            $responseCode = Response::HTTP_CREATED;
            $apiResource = $this->createApiResource();

            $this->eventDispatcher->dispatch(
                new ApiResourceEvent($apiResource),
                HessnaturSimpleRestCRUDEvents::AFTER_INSTANTIATE_API_RESOURCE
            );

            if (!$apiResource->getUserCanCreate()) {
                throw new AccessDeniedHttpException();
            }
        }

        $form = $this->formFactory->createNamed(null, $this->getApiResourceFormClass(), $apiResource);
        $this->requestStack->getMasterRequest()->request->remove('_method');
        $form->submit($this->requestStack->getMasterRequest()->request->all());

        if ($form->isValid()) {
            $this->eventDispatcher->dispatch(
                new ApiResourceEvent($apiResource),
                $responseCode === Response::HTTP_CREATED
                    ? HessnaturSimpleRestCRUDEvents::BEFORE_CREATE_API_RESOURCE
                    : HessnaturSimpleRestCRUDEvents::BEFORE_UPDATE_API_RESOURCE
            );
            $this->apiResourceManager->update($apiResource);
            $this->eventDispatcher->dispatch(
                new ApiResourceEvent($apiResource),
                $responseCode === Response::HTTP_CREATED
                    ? HessnaturSimpleRestCRUDEvents::AFTER_CREATE_API_RESOURCE
                    : HessnaturSimpleRestCRUDEvents::AFTER_UPDATE_API_RESOURCE
            );


            return View::create($apiResource, $responseCode)
                ->setTemplate('@HessnaturSimpleRestCRUD/ApiResource/form.html.twig')
                ->setTemplateData(['form'=>$form->createView()])
                ;
        }

        return View::create(['form' => $form], Response::HTTP_BAD_REQUEST)
            ->setTemplate('@HessnaturSimpleRestCRUD/ApiResource/form.html.twig')
            ->setTemplateData(['form' => $form->createView()])
            ;
    }

    /**
     * @return ApiResource
     */
    protected function createApiResource()
    {
        $apiResourceClass = $this->getApiResourceClass();

        return new $apiResourceClass();
    }

    /**
     * @param string $id
     *
     * @return ApiResource|object
     */
    protected function fetchApiResource(string $id)
    {
        $repository = $this->apiResourceManager->getRepository($this->getApiResourceClass());
        $apiResource = $repository->findOneBy(['id' => $id]);
        $apiClassName = $this->getApiResourceClass();
        if (
            null === $apiResource
            || !$apiResource instanceof $apiClassName
        ) {
            throw new NotFoundHttpException();
        }

        return $apiResource;
    }

    /**
     * @param QueryBuilder $queryBuilder
     *
     * @return array
     */
    protected function paginate(QueryBuilder $queryBuilder)
    {
        $page = intval($this->requestStack->getCurrentRequest()->get('page', 1));
        if ($page === 0) {
            $page = 1;
        }

        $limit = intval($this->requestStack->getCurrentRequest()->get('limit', $this->getApiResourceListLimit()));
        if ($limit === 0 || $limit > $this->getApiResourceListLimit()) {
            $limit = $this->getApiResourceListLimit();
        }

        $results = $queryBuilder->getQuery()->getResult();
        $paginationData = [
            'limit' => $limit,
            'maxResults' => count($results),
            'results' => array_slice($results, ($page - 1) * $limit, $limit),
            'pages' => ceil(count($results) / $limit),
            'currentPage' => $page,
        ];

        return $paginationData;
    }

    /**
     * @param string|null $alias
     *
     * @return QueryBuilder
     */
    protected function createQueryBuilder(?string $alias = null)
    {
        if ($alias === null) {
            $alias = 'e';
        }

        return $this->getRepository()->createQueryBuilder($alias);
    }

    /**
     * @return ApiResourceRepositoryInterface
     */
    protected function getRepository()
    {
        $repository = $this->apiResourceManager->getRepository($this->getApiResourceClass());
        if (!$repository instanceof ApiResourceRepositoryInterface) {
            throw new \LogicException(
                sprintf(
                    'You need to use repository %s to use %s, %s given',
                    ApiResourceRepositoryInterface::class,
                    __CLASS__,
                    get_class($repository)
                )
            );
        }

        return $repository;
    }
}

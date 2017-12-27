<?php

namespace Gotoemma\MediaApiBundle\Controller;

use Imagine\Exception\RuntimeException;
use Liip\ImagineBundle\Exception\Imagine\Filter\NonExistingFilterException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Imagine\Cache\SignerInterface;
use Psr\Log\LoggerInterface;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImagineController
{
    /**
     * @var DataManager
     */
    protected $dataManager;

    /**
     * @var FilterManager
     */
    protected $filterManager;

    /**
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * @var SignerInterface
     */
    protected $signer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /** @var  ContainerInterface */
    protected $container;

    /**
     * @param DataManager     $dataManager
     * @param FilterManager   $filterManager
     * @param CacheManager    $cacheManager
     * @param ContainerInterface    $containerInterface
     * @param SignerInterface $signer
     */
    public function __construct(
        DataManager $dataManager,
        FilterManager $filterManager,
        CacheManager $cacheManager,
        SignerInterface $signer,
        ContainerInterface $containerInterface,
        LoggerInterface $logger = null
    ) {
        $this->dataManager = $dataManager;
        $this->filterManager = $filterManager;
        $this->cacheManager = $cacheManager;
        $this->signer = $signer;
        $this->container = $containerInterface;
        $this->logger = $logger;
    }

    /**
     * This action applies a given filter to a given image, optionally saves the image and outputs it to the browser at the same time.
     *
     * @param Request $request
     * @param string  $path
     * @param string  $filter
     *
     * @throws \RuntimeException
     * @throws BadRequestHttpException
     *
     * @return RedirectResponse
     */
    public function filterAction(Request $request, $path, $filter)
    {
        // decoding special characters and whitespaces from path obtained from url
        $pathToGetBinary = $path = urldecode($path);

        preg_match('@([^/]*)/(.*)/([a-z0-9\-]*)_([a-z_A-Z]*).jpg@', $path, $matches);

        $resolver = $request->get('resolver');

        try {

            if (!$this->cacheManager->isStored($path, $filter, $resolver)) {
                try {

                    if (count($matches)) {
                        // get the file
                        $media = $this->getMedia($matches[3]);
                        if (!$media) {
                            throw new NotFoundHttpException();
                        }

                        $provider = $this->getProvider($media);
                        $path_pdf = $provider->getCdnPath($provider->getReferenceImage($media), $media->getCdnIsFlushable());
                        $pathToGetBinary = pathinfo($path_pdf, PATHINFO_DIRNAME) . '/' . pathinfo($path_pdf, PATHINFO_FILENAME) . '.jpg';

                    }

                    $binary = $this->dataManager->find($filter, $pathToGetBinary);
                } catch (NotLoadableException $e) {
                    if ($defaultImageUrl = $this->dataManager->getDefaultImageUrl($filter)) {
                        return new RedirectResponse($defaultImageUrl);
                    }

                    throw new NotFoundHttpException('Source image could not be found', $e);
                }

                $this->cacheManager->store(
                    $this->filterManager->applyFilter($binary, $filter),
                    $path,
                    $filter,
                    $resolver
                );
            }

            return new RedirectResponse($this->cacheManager->resolve($path, $filter, $resolver), 301);
        } catch (NonExistingFilterException $e) {
            $message = sprintf('Could not locate filter "%s" for path "%s". Message was "%s"', $filter, $path, $e->getMessage());

            if (null !== $this->logger) {
                $this->logger->debug($message);
            }

            throw new NotFoundHttpException($message, $e);
        } catch (RuntimeException $e) {
            throw new \RuntimeException(sprintf('Unable to create image for path "%s" and filter "%s". Message was "%s"', $path, $filter, $e->getMessage()), 0, $e);
        }
    }

    /**
     * This action applies a given filter to a given image, optionally saves the image and outputs it to the browser at the same time.
     *
     * @param Request $request
     * @param string  $hash
     * @param string  $path
     * @param string  $filter
     *
     * @throws \RuntimeException
     * @throws BadRequestHttpException
     *
     * @return RedirectResponse
     */
    public function filterRuntimeAction(Request $request, $hash, $path, $filter)
    {
        $resolver = $request->get('resolver');

        try {
            $filters = $request->query->get('filters', array());

            if (!is_array($filters)) {
                throw new NotFoundHttpException(sprintf('Filters must be an array. Value was "%s"', $filters));
            }

            if (true !== $this->signer->check($hash, $path, $filters)) {
                throw new BadRequestHttpException(sprintf(
                    'Signed url does not pass the sign check for path "%s" and filter "%s" and runtime config %s',
                    $path,
                    $filter,
                    json_encode($filters)
                ));
            }

            try {
                $binary = $this->dataManager->find($filter, $path);
            } catch (NotLoadableException $e) {
                if ($defaultImageUrl = $this->dataManager->getDefaultImageUrl($filter)) {
                    return new RedirectResponse($defaultImageUrl);
                }

                throw new NotFoundHttpException(sprintf('Source image could not be found for path "%s" and filter "%s"', $path, $filter), $e);
            }

            $rcPath = $this->cacheManager->getRuntimePath($path, $filters);

            $this->cacheManager->store(
                $this->filterManager->applyFilter($binary, $filter, array(
                    'filters' => $filters,
                )),
                $rcPath,
                $filter,
                $resolver
            );

            return new RedirectResponse($this->cacheManager->resolve($rcPath, $filter, $resolver), 301);
        } catch (NonExistingFilterException $e) {
            $message = sprintf('Could not locate filter "%s" for path "%s". Message was "%s"', $filter, $hash.'/'.$path, $e->getMessage());

            if (null !== $this->logger) {
                $this->logger->debug($message);
            }

            throw new NotFoundHttpException($message, $e);
        } catch (RuntimeException $e) {
            throw new \RuntimeException(sprintf('Unable to create image for path "%s" and filter "%s". Message was "%s"', $hash.'/'.$path, $filter, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param string $id
     *
     * @return MediaInterface
     */
    public function getMedia($id)
    {
        return $this->container->get('sonata.media.manager.media')->find($id);
    }

    /**
     * @param MediaInterface $media
     *
     * @return MediaProviderInterface
     */
    public function getProvider(MediaInterface $media)
    {
        return $this->container->get('sonata.media.pool')->getProvider($media->getProviderName());
    }
}

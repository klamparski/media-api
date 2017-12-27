<?php

namespace Gotoemma\MediaApiBundle\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class PostLoadEventListener
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(ContainerInterface $container, RequestStack $requestStack)
    {
        $this->container = $container;
        $this->requestStack = $requestStack;
    }

    public function postLoad(LifecycleEventArgs $args)
    {
        $class = $this->container->getParameter('sonata.media.media.class');
        $entity = $args->getEntity();
        if ($entity instanceof $class && $entity->getProviderName()) {
            /** @var MediaProviderInterface $provider */
            $provider = $this->container->get($entity->getProviderName());
            foreach ($provider->getFormats() AS $key => $defintion) {
                if ($key === 'admin') {
                    return;
                }

                list($context, $formatName) = explode('_', $key);
                $format = $provider->getHelperProperties($entity, $key);

                if (isset($format['src']) && strpos(
                        $format['src'],
                        '/'
                    ) === 0 && $this->requestStack->getCurrentRequest()) {
                    $format['src'] = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost().$format['src'];
                }
                $format['context'] = $context;
                $format['format'] = $formatName;
                $entity->addFormat($format);
            }
        }
    }
}

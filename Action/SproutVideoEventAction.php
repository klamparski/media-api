<?php

namespace Ins\MediaApiBundle\Action;

use Ins\MediaApiBundle\Provider\SproutVideoProvider;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sonata\CoreBundle\Model\ManagerInterface;
use Sonata\MediaBundle\Tests\Entity\Media;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

class SproutVideoEventAction
{
	/**
	 * @var SerializerInterface
	 */
	private $serializer;

    /**
     * @var ManagerInterface
     */
    private $mediaManager;

	/**
	 * @var EventDispatcherInterface
	 */
	private $eventDispatcher;

    /**
     * @var SproutVideoProvider
     */
    private $provider;

	public function __construct(SerializerInterface $serializer, ManagerInterface $mediaManager, EventDispatcherInterface $eventDispatcher, SproutVideoProvider $provider) {
		$this->serializer = $serializer;
		$this->mediaManager = $mediaManager;
		$this->eventDispatcher = $eventDispatcher;
        $this->provider = $provider;
	}

	/**
	 * @param Request $request
	 * @return Response
	 *
	 * @Route(
	 *     name="media_api_sprout_video_event",
	 *     path="/webhook/sproutvideo/event",
	 * )
	 * @Method("POST")
	 */
	public function __invoke(Request $request)
	{
		if ($request->getContentType() !== 'json')
		{
			return null;
		}

        $video = json_decode($request->getContent(), true);

		/** @var Media $mediaElement */
        $mediaElement = $this->mediaManager->findOneBy(array('providerReference' => $video['id']));

        $this->provider->updateMetadata($mediaElement);
        $this->mediaManager->save($mediaElement, true);

		return new Response(null, Response::HTTP_OK);
	}
}

<?php
/**
 * @file
 * Contains \Drupal\example\Controller\ExampleController.
 */
namespace Drupal\example\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
class ExampleController extends ControllerBase {
	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container) {
		return new static(
			$container->get('module_handler')
		);
	}
	/**
	 * {@inheritdoc}
	 */
	public function content() {
		$build = array(
			'#type' => 'markup',
			'#markup' => t('Hello World!'),
		);
		return $build;
	}
}
?>
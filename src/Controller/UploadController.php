<?php
/**
 * @file
 * Contains \Drupal\ww_enterprise\Controller\UploadController.
 */
namespace Drupal\ww_enterprise\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class UploadController
 *
 * Used to provide http upload functionality from Enterprise Server.
 *
 * See ww_enterprise.info.yml, the route defined there leads to this
 * class to handle the uploads and store the data in Drupal.
 *
 * @package Drupal\ww_enterprise\Controller
 */
class UploadController extends ControllerBase
{
	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container)
	{
		return new static( $container->get('module_handler' ) );
	}

	/**
	 * Responsible for handling files uploaded to the ww_enterprise module.
	 *
	 * - Creates a subdirectory if it was defined for the field the file is being uploaded for.
	 * - Reads out the fields uri scheme and uses this when storing the files.
	 * - Files are stored in the database and entered in the file_managed table.
	 * - Files are made permanent to prevent deletion by Drupal at intervals.
	 *
	 * The resulting xml response is printed to the screen and is used by Enterprise Server to retrieve
	 * the file id's.
	 */
	public function upload()
	{
		// Authenticate the request.
		/** @var \Drupal\user\Entity\User $user */
		$user = validateRequest( \Drupal::request() );
		if ( is_null( $user ) ) {
			\Drupal::logger( 'ww_enterprise' )->debug( 'Could not authenticate the user.' );
			throw new \Exception( 'Could not authenticate the user.' );
		}

		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		$uploadResponseElement = $dom->createElement( 'UploadResponse' );
		try {
			$uid = $user->id();

			// Find the ContentType for the passed GUID.
			$originalUuid = $_GET['content_type'];
			$originalType = null;
			$id = null;
			$types = \Drupal\node\Entity\NodeType::loadMultiple();
			/** @var \Drupal\node\Entity\NodeType $type */
			if ( $types ) foreach ( $types as $type ) {
				if ( $type->uuid() == $originalUuid ) {
					$originalType = $type->type;
					$id = $type->id();
					break;
				}
			}

			if ( is_null( $originalType ) ) {
				throw new \Exception( 'Attempting to save a node with an unknown Node Type UUID: ' . $originalUuid . '.' );
			}

			// Check access rights.
			$message = '';
			if ( !accessCheck( $user, 'uploadFile', $message, $id ) ) {
				\Drupal::logger( 'ww_enterprise' )->debug( 'uploadFile: ' . $message );
				throw new \Exception( $message );
			}

			// Find the correct field machine name for the passed id.
			$fieldId = $_GET['field_id'];
			$field_machine_name = retrieveMachineName( $fieldId );

			if ( is_null( $field_machine_name ) ) {
				throw new \Exception( 'Unable to load the field belonging to ID: ' . $fieldId . '.' );
			}

			// Load the content type to get the validation rule for the field extensions.
			$fields = \Drupal::entityManager()->getFieldDefinitions( 'node', $originalType );
			$validators = array( 'file_validate_extensions' => array() );
			$filters = array();

			/** @var \Drupal\field\Entity\FieldConfig $field */
			$subDirectory = '';
			$scheme = 'public://';
			foreach ( $fields as $field ) {
				if ( $field instanceof \Drupal\field\Entity\FieldConfig ) {
					// Determine the file properties.
					if ( $field->getName() == $field_machine_name ) {
						$fieldType = $field->getType();

						// For certain field types (typically field types that can support inline images), there are
						// no extra settings to be configured before the upload, therefore skip them, this counts for
						//  text (formatted, long) and text (formatted, long, summary)
						if( $fieldType != 'text_long' && $fieldType != 'text_with_summary' ) {
							$values = $field->getSetting( 'file_extensions' );
							$subDirectory = $field->getSetting( 'file_directory' );
							$scheme = $field->getFieldStorageDefinition()->getSetting( 'uri_scheme' ) . '://';

							if ( !empty( $values ) ) {
								$filters = array( $values );
								break;
							}
						}
					}
				}
			}

			$validators['file_validate_extensions'] = $filters;
			$path = $scheme . $subDirectory;

			if ( !is_dir( $path ) ) {
				drupal_mkdir( $path, null, true );
			}
			$files = file_save_upload( 'upload', $validators, $path, null, FILE_EXISTS_RENAME );
			if ( empty ( $files ) ) {
				throw new \Exception( 'Upload failed.' );
			}

			// Check if the file was uploaded correctly.
			$errors = drupal_get_messages( 'error' );
			if ( is_array( $errors ) && !empty( $errors ) && isset( $errors['error'] ) ) {
				$errorToThrow = implode( 'PHP_EOL', $errors['error'] );
				throw new \Exception( $errorToThrow );
			}

			// The file needs to be updated with an owner and needs to be made permanent. Initially the file is set as
			// non-permanent and the owner is set to id 0. If we don't set the file as a permanent file then Drupal might
			// automatically remove it.
			/** @var \Drupal\file\Entity\File $file */
			$file = $files[0];
			$file->setPermanent();
			$file->setOwnerId( $uid );
			$file->save();

			$fidElement = $dom->createElement( 'fid' );
			$fidElement->appendChild( $dom->createTextNode( $file->id() ) );
			$uploadResponseElement->appendChild( $fidElement );
		} catch( \Exception $e ) {
			\Drupal::logger( 'ww_enterprise' )->error( 'Uploading a file failed: ' . $e->getMessage() );
			$errorElement = $dom->createElement( 'error' );
			$errorElement->appendChild( $dom->createTextNode( $e->getMessage() ) );
			$uploadResponseElement->appendChild( $errorElement );
		}
		$dom->appendChild( $uploadResponseElement );
		print $dom->saveXML();
		exit;
	}
}
?>
<?php

class Cloudinary_WP_Integration {

	private static $instance;

	/**
	 * Singleton.
	 *
	 * @return Cloudinary_WP_Integration
	 */
	public static function get_instance() {

		//////////
		error_log( 'get_instance() start', 0 );
		//////////

		if ( ! self::$instance ) {
			self::$instance = new Cloudinary_WP_Integration();
		}

		//////////
		error_log( 'get_instance() is about to return self::$instance', 0 );
		//////////

		return self::$instance;

	}

	public function __construct() {
	}

	/**
	 * Run setup for the plugin.
	 */
	public function setup() {

		//////////
		error_log( 'setup() start', 0 );
		//////////

		$this->register_hooks();

		//////////
		error_log( 'setup() end', 0 );
		//////////

	}

	/**
	 * Handle filter/hook registration.
	 */
	public function register_hooks() {

		//////////
		error_log( 'register_hooks() start', 0 );
		//////////

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_cloudinary_data' ) );
		// add_filter( 'wp_get_attachment_url', array( $this, 'get_attachment_url' ), 10, 2 );
		// add_filter( 'image_downsize', array( $this, 'image_downsize' ), 10, 3 );

		// Filter images created on the fly.
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), 10, 3 );

		// Replace the default WordPress content filter with our own.
		remove_filter( 'the_content', 'wp_make_content_images_responsive' );
		add_filter( 'the_content', array( $this, 'make_content_images_responsive' ) );

		//////////
		error_log( 'register_hooks() end', 0 );
		//////////

	}

	/**
	 * Adds metadata from Cloudinary to an image.
	 *
	 * @param array $metadata WordPress genereated metadata.
	 * @return array The filtered metadata.
	 */
	public function generate_cloudinary_data( $metadata ) {

		//////////
		error_log( 'generate_cloudinary_data() start', 0 );
		//////////

		// Bail early if we don't have a file path to work with.
		if ( ! isset( $metadata['file'] ) ) {
			return $metadata;
		}

		$uploads = wp_get_upload_dir();
		$filepath = trailingslashit( $uploads['basedir'] ) . $metadata['file'];

		// Mirror the image on Cloudinary, and buld custom metadata from the response.
		if ( $data = $this->handle_upload( $filepath ) ) {
			$metadata['cloudinary_data'] = array(
				'public_id'  => $data['public_id'],
				'width'      => $data['width'],
				'height'     => $data['height'],
				'bytes'      => $data['bytes'],
				'url'        => $data['url'],
				'secure_url' => $data['secure_url'],
			);

			foreach ( $data['responsive_breakpoints'][0]['breakpoints'] as $size ) {
				$metadata['cloudinary_data']['sizes'][ $size['width'] ] = $size;
			}
		};

		//////////
		error_log( 'generate_cloudinary_data() is about to return $metadata', 0 );
		error_log( $metadata, 0 );
		//////////

		return $metadata;

	}

	/**
	 * Uploads an image file to Cloudinary.
	 *
	 * @param string $file The path to an image file.
	 * @return array|false The image data returned from the Cloudinary API.
	 *                     False on error.
	 */
	public function handle_upload( $file ) {

		//////////
		error_log( 'handle_upload() start', 0 );
		//////////

		$data = false;

		if ( is_callable( array( '\Cloudinary\Uploader', 'upload' ) ) ) {
			$api_args = array(
				'responsive_breakpoints' => array(
					array(
						'create_derived' => false,
						'bytes_step'		 => 20000,
						'min_width'			=> 200,
						'max_width'			=> 1000,
						'max_images'		 => 20,
					),
				),
				'use_filename' => true,
			);

			$response = \Cloudinary\Uploader::upload( $file, $api_args );

			// Check for a valid response before returning Cloudinary data.
			$data = isset( $response['public_id'] ) ? $response : false;
		}

		//////////
		error_log( 'handle_upload() is about to return $data', 0 );
		error_log( $data, 0 );
		//////////

		return $data;

	}

	/**
	 * Get a Cloudinary URL for an image.
	 *
	 * @param string $url           Local URL for an image.
	 * @param int    $attachment_id Attachment ID.
	 * @return string The Cloudinary URL if it exists, or the local URL.
	 */
	public function get_attachment_url( $url, $attachment_id ) {

		//////////
		error_log( 'get_attachment_url() start', 0 );
		//////////

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['cloudinary_data']['secure_url'] ) ) {
			$url = $metadata['cloudinary_data']['secure_url'];
		}

		//////////
		error_log( 'get_attachment_url() is about to return $url', 0 );
		error_log( $url );
		//////////

		return $url;

	}

	/**
	 * Filter image attributes based on Cloudinary data.
	 *
	 * @param bool         $downsize      Defaults to false.
	 * @param int          $attachment_id Attachment ID.
	 * @param array|string $size          Size of image. Image size or array of width and
	 *                                    height values (in that order). Default 'medium'.
	 * @return array Array containing the image URL, width, height, and boolean for whether
	 *               the image is an intermediate size.
	 */
	public function image_downsize( $downsize, $attachment_id, $size ) {

		//////////
		error_log( 'image_downsize() start', 0 );
		//////////

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['cloudinary_data']['secure_url'] ) ) {
			$sizes = $this->get_wordpress_image_size_data( $size );

			// If we found size data, let's figure out our own downsize attributes.
			if ( is_string( $size ) && isset( $sizes[ $size ] ) &&
				( $sizes[ $size ]['width'] <= $metadata['cloudinary_data']['width'] ) &&
				( $sizes[ $size ]['height'] <= $metadata['cloudinary_data']['height'] ) ) {

				$width = $sizes[ $size ]['width'];
				$height = $sizes[ $size ]['height'];

				$dims = image_resize_dimensions( $metadata['width'], $metadata['height'], $sizes[ $size ]['width'], $sizes[ $size ]['height'], $sizes[ $size ]['crop'] );

				if ( $dims ) {
					$width = $dims[4];
					$height = $dims[5];
				}

				$crop = ( $sizes[ $size ]['crop'] ) ? 'c_lfill' : 'c_limit';

				$url_params = "w_$width,h_$height,$crop,q_auto,f_auto";

				$downsize = array(
					str_replace( '/image/upload', '/image/upload/' . $url_params, $metadata['cloudinary_data']['secure_url'] ),
					$width,
					$height,
					true,
				);
			} elseif ( is_array( $size ) ) {
				$downsize = array(
					str_replace( '/image/upload', "/image/upload/w_$size[0],h_$size[1],c_limit", $metadata['cloudinary_data']['secure_url'] ),
					$size[0],
					$size[1],
					true,
				);
			}
		}

		//////////
		error_log( 'image_downsize() is about to return $downsize', 0 );
		error_log( $downsize, 0 );
		//////////

		return $downsize;
	}

	/**
	 * Get data about registered image sizes in WordPress.
	 *
	 * @param string $size Optional. A registered image size name.
	 * @return array An array containing width, height, and crop information.
	 */
	private function get_wordpress_image_size_data( $size = null ) {

		//////////
		error_log( 'get_wordpress_image_size_data() start', 0 );
		//////////

		global $_wp_additional_image_sizes;

		$sizes = array();

		foreach ( get_intermediate_image_sizes() as $s ) {
			// Skip over sizes we're not returning.
			if ( $size && $size != $s ) {
				continue;
			}

			$sizes[ $s ] = array( 'width' => '', 'height' => '', 'crop' => false );

			// Set the width.
			if ( isset( $_wp_additional_image_sizes[ $s ]['width'] ) ) {
				// For theme-added sizes.
				$sizes[ $s ]['width'] = intval( $_wp_additional_image_sizes[ $s ]['width'] );
			} else {
				// For default sizes set in options.
				$sizes[ $s ]['width'] = get_option( "{$s}_size_w" );
			}

			// Set the height.
			if ( isset( $_wp_additional_image_sizes[ $s ]['height'] ) ) {
				// For theme-added sizes.
				$sizes[ $s ]['height'] = intval( $_wp_additional_image_sizes[ $s ]['height'] );
			} else {
				// For default sizes set in options.
				$sizes[ $s ]['height'] = get_option( "{$s}_size_h" );
			}

			// Set the crop value.
			if ( isset( $_wp_additional_image_sizes[ $s ]['crop'] ) ) {
				// For theme-added sizes.
				$sizes[ $s ]['crop'] = $_wp_additional_image_sizes[ $s ]['crop'];
			} else {
				// For default sizes set in options.
				$sizes[ $s ]['crop'] = get_option( "{$s}_crop" );
			}
		}

		//////////
		error_log( 'get_wordpress_image_size_data() is about to return $sizes', 0 );
		error_log( $sizes, 0 );
		//////////

		return $sizes;
	}

	/**
	 * Filters attachment attributes to use Cloudinary data.
	 *
	 * @param array       $attr       Attributes for the image markup.
	 * @param WP_Post     $attachment Image attachment post.
	 * @param string|arra $size       Requested size. Image size or array of width and
	 *                                height values (in that order). Default 'thumbnail'.
	 * @return array Filtered attributes for the image markup.
	 */
	public function wp_get_attachment_image_attributes( $attr, $attachment, $size ) {

		//////////
		error_log( 'wp_get_attachment_image_attributes() start', 0 );
		//////////

		$metadata = wp_get_attachment_metadata( $attachment->ID );

		$width = $height = false;

		if ( is_string( $size ) ) {
			if ( 'full' === $size ) {
				$width = $attachment['width'];
				$height = $attachment['height'];
			} elseif ( $data = $this->get_wordpress_image_size_data( $size ) ) {
				// Bail early if this is a cropped image size.
				if ( $data[ $size ]['crop'] ) {
					return $attr;
				}

				$width = $data[ $size ]['width'];
				$height = $data[ $size ]['height'];
			}
		} elseif ( is_array( $size ) ) {
			list( $width, $height ) = $size;
		}

		if ( isset( $metadata['cloudinary_data']['sizes'] ) ) {
			$srcset = '';

			foreach ( $metadata['cloudinary_data']['sizes'] as $s ) {
				$srcset .= $s['secure_url'] . ' ' . $s['width'] . 'w, ';
			}

			if ( ! empty( $srcset ) ) {
				$attr['srcset'] = rtrim( $srcset, ', ' );
				$sizes = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );

				// Convert named size to dimension array to workaround TwentySixteen bug.
				$size = array( $width, $height );
				$attr['sizes'] = apply_filters( 'wp_calculate_image_sizes', $sizes, $size, $attr['src'], $metadata, $attachment->ID );
			}
		}

		//////////
		error_log( 'wp_get_attachment_image_attributes() is about to return $attr', 0 );
		error_log( $attr );
		//////////

		return $attr;
	}

	public function make_content_images_responsive( $content ) {

		//////////
		error_log( 'make_content_images_responsive() start', 0 );
		//////////

		if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) ) {

			//////////
			error_log( 'make_content_images_responsive() is returning early because it couldn’t find any <img>s', 0 );
			//////////

			return $content;

		}

		$selected_images = $attachment_ids = array();

		foreach( $matches[0] as $image ) {
			if (
				false === strpos( $image, ' srcset=' ) &&
				preg_match( '/wp-image-([0-9]+)/i', $image, $class_id ) &&
				( $attachment_id = absint( $class_id[1] ) )
			) {

				//////////
				error_log( $image . "doesn't have a srcset already, has class wp-image-X, and something something appears more than once", 0 );
				//////////

				/*
				 * If exactly the same image tag is used more than once, overwrite it.
				 * All identical tags will be replaced later with 'str_replace()'.
				 */
				$selected_images[ $image ] = $attachment_id;
				// Overwrite the ID when the same image is included more than once.
				$attachment_ids[ $attachment_id ] = true;
			}
		}

		if ( count( $attachment_ids ) > 1 ) {
			/*
			 * Warm object cache for use with 'get_post_meta()'.
			 *
			 * To avoid making a database call for each image, a single query
			 * warms the object cache with the meta information for all images.
			 */
			update_meta_cache( 'post', array_keys( $attachment_ids ) );
		}

		foreach ( $selected_images as $image => $attachment_id ) {
			$image_meta = wp_get_attachment_metadata( $attachment_id );
			$content = str_replace( $image, $this->add_srcset_and_sizes( $image, $image_meta, $attachment_id ), $content );
		}

		//////////
		error_log( 'make_content_images_responsive() is about to return $content', 0 );
		error_log( $content, 0 );
		//////////

		return $content;
	}

	/**
	 * Adds 'srcset' and 'sizes' attributes to an image.
	 *
	 * @param string $image          An HTML img element.
	 * @param array  $image_meta     Attachment metadata for the image.
	 * @param int    $attachment_id Image attachment ID.
	 * @return string Converted 'img' element with 'srcset' and 'sizes' attributes added.
	 */
	public function add_srcset_and_sizes( $image, $image_meta, $attachment_id ) {

		//////////
		error_log( 'add_srcset_and_sizes() start', 0 );
		//////////

		if ( isset( $image_meta['cloudinary_data']['sizes'] ) ) {
			$src = preg_match( '/src="([^"]+)"/', $image, $match_src ) ? $match_src[1] : '';
			// See if our filename is in the URL string.
			if ( false !== strpos( $src, pathinfo( $image_meta['file'], PATHINFO_FILENAME ) ) && false === strpos( $image, 'c_lfill' ) ) {
				/*
				 * Dimensions cannot be parsed from the markup, so we're estimating for
				 * now. Would be better to replace these values with something meaningful
				 * since the width is used for the `sizes` attribute.
				 */
				$width  = 600;
				$height = 400;

				$srcset = '';

				foreach ( $image_meta['cloudinary_data']['sizes'] as $s ) {

					// add f_auto and q_auto to our transformations...

					// only matches if there are transformations, captures them
					$get_the_transformations_regex = '/\/image\/upload\/([^\/]+)\/v\d+\//';

					// build an array of existing (if any) + new transformations
					if ( preg_match( $get_the_transformations_regex, $s['secure_url'], $matches ) ) {
						$transformations = explode( ',', $matches[1] );
					} else {
						$transformations = array();
					}
					array_push( $transformations, 'f_auto', 'q_auto' );

					// matches whether or not there are transformations, captures them + version number
					$put_the_transformations_regex = '/\/image\/upload\/([^\/]+\/)?(v\d+\/)/';

					$transformed_url = preg_replace( $put_the_transformations_regex, '/image/upload/' . implode( ',', $transformations) . '/$2', $s['secure_url'] );

					$srcset .= $transformed_url . ' ' . $s['width'] . 'w, ';

				}

				if ( ! empty( $srcset ) ) {
					$srcset = rtrim( $srcset, ', ' );
					$sizes = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );

					// Convert named size to dimension array.
					$size = array( $width, $height );
					$sizes = apply_filters( 'wp_calculate_image_sizes', $sizes, $size, $src, $image_meta, $attachment_id );
				}

				$image = preg_replace( '/src="([^"]+)"/', 'src="$1" srcset="' . $srcset . '" sizes="' . $sizes . '"', $image );
			}


		} else {

			/* It's not a new image and hasn't been mirrored to Cloudinary
			 * so build a WordPress `srcset`, just like would have without the plugin
			 * Except! Serve up the images from Cloudinary, with `fetch` URLs
			 *
			 * (this is all copied over from /wp-includes/media.php, lines 1366-1436)
			 */

			// Ensure the image meta exists.
			if ( empty( $image_meta['sizes'] ) ) {
				return $image;
			}

			$image_src = preg_match( '/src="([^"]+)"/', $image, $match_src ) ? $match_src[1] : '';
			list( $image_src ) = explode( '?', $image_src );

			// Return early if we couldn't get the image source.
			if ( ! $image_src ) {
				return $image;
			}

			// Bail early if an image has been inserted and later edited.
			if ( preg_match( '/-e[0-9]{13}/', $image_meta['file'], $img_edit_hash ) &&
				strpos( wp_basename( $image_src ), $img_edit_hash[0] ) === false ) {

				return $image;
			}

			$width  = preg_match( '/ width="([0-9]+)"/',  $image, $match_width  ) ? (int) $match_width[1]  : 0;
			$height = preg_match( '/ height="([0-9]+)"/', $image, $match_height ) ? (int) $match_height[1] : 0;

			if ( ! $width || ! $height ) {
				/*
				 * If attempts to parse the size value failed, attempt to use the image meta data to match
				 * the image file name from 'src' against the available sizes for an attachment.
				 */
				$image_filename = wp_basename( $image_src );

				if ( $image_filename === wp_basename( $image_meta['file'] ) ) {
					$width = (int) $image_meta['width'];
					$height = (int) $image_meta['height'];
				} else {
					foreach( $image_meta['sizes'] as $image_size_data ) {
						if ( $image_filename === $image_size_data['file'] ) {
							$width = (int) $image_size_data['width'];
							$height = (int) $image_size_data['height'];
							break;
						}
					}
				}
			}

			if ( ! $width || ! $height ) {
				return $image;
			}

			$size_array = array( $width, $height );
			$srcset = preg_replace( '/(https?:\/\/)/', 'https://res.cloudinary.com/' . CLD_CLOUD_NAME . '/image/fetch/q_auto,f_auto/$1', wp_calculate_image_srcset( $size_array, $image_src, $image_meta, $attachment_id ) );

			if ( $srcset ) {
				// Check if there is already a 'sizes' attribute.
				$sizes = strpos( $image, ' sizes=' );

				if ( ! $sizes ) {
					$sizes = wp_calculate_image_sizes( $size_array, $image_src, $image_meta, $attachment_id );
				}
			}

			if ( $srcset && $sizes ) {
				// Format the 'srcset' and 'sizes' string and escape attributes.
				$attr = sprintf( ' srcset="%s"', esc_attr( $srcset ) );

				if ( is_string( $sizes ) ) {
					$attr .= sprintf( ' sizes="%s"', esc_attr( $sizes ) );
				}

				// Add 'srcset' and 'sizes' attributes to the image markup.
				$image = preg_replace( '/<img ([^>]+?)[\/ ]*>/', '<img $1' . $attr . ' />', $image );
			}

		}

		//////////
		error_log( 'add_srcset_and_sizes() is about to return $image', 0 );
		error_log( $image, 0 );
		//////////

		return $image;
	}
}

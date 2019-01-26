<?php
/*
License: GPL2

	Copyright 2011–2015  Bjørn Johansen  (email : post@bjornjohansen.no)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as 
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

/**
 * The class that handles rewriting of content so we can lazy load it
 */
class BJLL {

	protected static $_options;


	function __construct( $options = null ) {

		add_action( 'wp', array( $this, 'init' ), 99 ); // run this as late as possible

	}

	/**
	 * Initialize the setup
	 */
	public function init() {

		/* We do not touch the feeds */
		if ( is_feed() ) {
			return;
		}

		self::_bjll_compat();
		do_action( 'bjll/compat' );

		/**
		 * Filter to let plugins decide whether the plugin should run for this request or not
		 *
		 * Returning false will effectively short-circuit the plugin
		 *
		 * @param bool $enabled Whether the plugin should run for this request
		 */
		$enabled = apply_filters( 'bjll/enabled', true );

		if ( $enabled ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			$this->_setup_filtering();
		}
	}


	/**
	 * Load compat script
	 */
	protected function _bjll_compat() {

		$dirname = trailingslashit( dirname( __FILE__ ) ) . 'compat';
		$d = dir( $dirname );
		if ( $d ) {
			while ( $entry = $d->read() ) {
				if ( '.' != $entry[0] && '.php' == substr( $entry, -4) ) {
					include trailingslashit( $dirname ) . $entry;
				}
			}
		}
	}

	/**
	 * Enqueue styles
	 */
	public function enqueue_styles() {

	}

	/**
	 * Enqueue scripts
	 */
	public function enqueue_scripts() {
		//$jsver = filemtime( dirname( dirname( __FILE__ ) ) . '/js/bj-lazy-load.js' );
		//wp_enqueue_script( 'BJLL', plugins_url( 'js/bj-lazy-load.js', dirname( __FILE__ ) ), null, $jsver, true );
		//$jsver = filemtime( dirname( dirname( __FILE__ ) ) . '/js/bj-lazy-load.v1.min.js' );
		$jsver = false;
		wp_enqueue_script( 'BJLL', plugins_url( 'js/bj-lazy-load.min.js', dirname( __FILE__ ) ), null, $jsver, true );

	}

	/**
	 * Set up filtering for certain content
	 */
	protected function _setup_filtering() {

		if ( ! is_admin() ) {

				add_filter( 'bjll/filter', array( __CLASS__, 'filter_images' ) );
			
				add_filter( 'the_content', array( __CLASS__, 'filter' ), 200 );
			

				add_filter( 'widget_text', array( __CLASS__, 'filter' ), 200 );
			

				add_filter( 'post_thumbnail_html', array( __CLASS__, 'filter' ), 200 );
			

				add_filter( 'get_avatar', array( __CLASS__, 'filter' ), 200 );
			

			add_filter( 'bj_lazy_load_html', array( __CLASS__, 'filter' ) );
		}

	}

	/**
	 * Filter HTML content. Replace supported content with placeholders.
	 *
	 * @param string $content The HTML string to filter
	 * @return string The filtered HTML string
	 */
	public static function filter( $content ) {

		// Last chance to bail out before running the filter
		$run_filter = apply_filters( 'bj_lazy_load_run_filter', true );
		if ( ! $run_filter ) {
			return $content;
		}

		/**
		 * Filter the content
		 *
		 * @param string $content The HTML string to filter
		 */
		$content = apply_filters( 'bjll/filter', $content );

		return $content;
	}


	/**
	 * Replace images with placeholders in the content
	 *
	 * @param string $content The HTML to do the filtering on
	 * @return string The HTML with the images replaced
	 */
	public static function filter_images( $content ) {

			$placeholder_url = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
		

		$match_content = self::_get_content_haystack( $content );

		$matches = array();
		preg_match_all( '/<img[\s\r\n]+.*?>/is', $match_content, $matches );
		
		$search = array();
		$replace = array();

		foreach ( $matches[0] as $imgHTML ) {
			
			// don't do the replacement if the image is a data-uri
			if ( ! preg_match( "/src=['\"]data:image/is", $imgHTML ) ) {
				
				$placeholder_url_used = $placeholder_url;

				// replace the src and add the data-src attribute
				$replaceHTML = preg_replace( '/<img(.*?)src=/is', '<img$1src="' . esc_attr( $placeholder_url_used ) . '" data-lazy-type="image" data-src=', $imgHTML );
				
				// also replace the srcset (responsive images)
				$replaceHTML = str_replace( 'srcset', 'data-srcset', $replaceHTML );
				// replace sizes to avoid w3c errors for missing srcset
				$replaceHTML = str_replace( 'sizes', 'data-sizes', $replaceHTML );
				
				// add the lazy class to the img element
				if ( preg_match( '/class=["\']/i', $replaceHTML ) ) {
					$replaceHTML = preg_replace( '/class=(["\'])(.*?)["\']/is', 'class=$1lazy lazy-hidden $2$1', $replaceHTML );
				} else {
					$replaceHTML = preg_replace( '/<img/is', '<img class="lazy lazy-hidden"', $replaceHTML );
				}
				
				$replaceHTML .= '<noscript>' . $imgHTML . '</noscript>';
				
				array_push( $search, $imgHTML );
				array_push( $replace, $replaceHTML );
			}
		}

		$content = str_replace( $search, $replace, $content );

		return $content;

	}

	/**
	 * Remove elements we don’t want to filter from the HTML string
	 *
	 * We’re reducing the haystack by removing the hay we know we don’t want to look for needles in
	 *
	 * @param string $content The HTML string
	 * @return string The HTML string without the unwanted elements
	 */
	protected static function _get_content_haystack( $content ) {
		$content = self::remove_noscript( $content );
		return $content;
	}

	/**
	 * Remove <noscript> elements from HTML string
	 *
	 * @author sigginet
	 * @param string $content The HTML string
	 * @return string The HTML string without <noscript> elements
	 */
	public static function remove_noscript( $content ) {
		return preg_replace( '/<noscript.*?(\/noscript>)/i', '', $content );
	}

}


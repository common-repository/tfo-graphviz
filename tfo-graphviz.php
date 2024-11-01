<?php
/**
 * tfo-graphviz.php
 *
 * @package tfo-graphviz
 *
 * TFO-Graphviz WordPress plugin
 * Copyright (C) 2010 Chris Luke <chrisy@flirble.org>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */


// $Id: tfo-graphviz.php 2111014 2019-06-23 22:16:58Z chrisy $
/*
Plugin Name: TFO Graphviz
Plugin URI: http://blog.flirble.org/projects/graphviz/
Description: Converts inline DOT code into an image, with optional image map, using Graphviz.
Version: 1.20
Author: Chris Luke
Author URI: http://blog.flirble.org/
Copyright: Chris Luke
Copyright: The Flirble Organisation
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) exit;

@define('TFO_GV_DEBUG', false);

class TFO_Graphviz {
	var $options;
	var $methods = array(
		'TFO_Graphviz_Graphviz' => 'graphviz',
		'TFO_Graphviz_Graphlib_Dot' => 'graphlib-dot',
		'TFO_Graphviz_PHP' => 'php',
	);
	var $method_label = array(
		'graphviz' => 'Local Graphviz installation using the <code>dot</code> Graphviz binary (<b>recommended</b>)',
		'graphlib-dot' => 'Uses the Graphlib-dot JavaScript module for in-browser rendering (<i>very</i> experimental, results will vary; in particular the library is known to have incomplete Graphviz dot comprehension)',
		'php' => 'Local PHP bindings for Graphviz (eg, <code>libgv-php5</code> on Debian/Ubuntu) (fastest)',
	);
	var $langs = array('dot', 'neato', 'twopi', 'circo', 'fdp');
	var $outputs = array('gif', 'png', 'jpg', 'svg');
	var $count, $err;


	/**
	 * Basic initialization.
	 */
	function init() {
		$this->options = get_option('tfo-graphviz');
		$this->count = 1;

		if (!isset($this->options['maxage'])) $this->options['maxage'] = 3600;
		if (!isset($this->options['emitjs'])) $this->options['emitjs'] = true;

		@define('TFO_GRAPHVIZ_CONTENT', 'tfo-graphviz');
		@define('TFO_GRAPHVIZ_CONTENT_DIR', WP_CONTENT_DIR.'/'.TFO_GRAPHVIZ_CONTENT);
		@define('TFO_GRAPHVIZ_CONTENT_URL', WP_CONTENT_URL.'/'.TFO_GRAPHVIZ_CONTENT);
		@define('TFO_GRAPHVIZ_GRAPHVIZ_PATH', $this->options['graphviz_path']);
		@define('TFO_GRAPHVIZ_MAXAGE', $this->options['maxage']);
		@define('TFO_GRAPHVIZ_EMITJS', $this->options['emitjs']);
		@define('TFO_GRAPHVIZ_SHORTCODE', 'graphviz');

		add_action('wp_head', array(&$this, 'wp_head'));
		add_shortcode(TFO_GRAPHVIZ_SHORTCODE, array(&$this, 'shortcode'));
		add_filter('no_texturize_shortcodes', array(&$this, 'shortcode_texturize_filer'));

		register_shutdown_function(array(&$this, 'cleanup_content_dir'));
	}


	/**
	 * Returns our shortcodes that should not be texturized.
	 **/
	function shortcode_texturize_filer($shortcodes) {
		$shortcodes[] = TFO_GRAPHVIZ_SHORTCODE;
		return $shortcodes;
	}


	/**
	 * Remove old files from the content directory.
	 */
	function cleanup_content_dir() {
		if (TFO_GRAPHVIZ_MAXAGE && TFO_GRAPHVIZ_MAXAGE > 0 && is_dir(TFO_GRAPHVIZ_CONTENT_DIR)) {
			$zaplist = array();
			if ($dh = @opendir(TFO_GRAPHVIZ_CONTENT_DIR)) {
				while (($fname = readdir($dh)) !== false) {
					$file = TFO_GRAPHVIZ_CONTENT_DIR.'/'.$fname;
					if (!is_file($file)) continue;

					$st = @stat($file);
					if (!$st) continue;

					if ((time() - $st['mtime']) > (TFO_GRAPHVIZ_MAXAGE * 24*60*60)) {
						array_push($zaplist, $file);
					}
				}
				closedir($dh);
			}
			foreach ($zaplist as $fname) {
				@unlink($fname);
			}
		}
	}


	/**
	 * Renders HTML output for the page head section.
	 */
	function wp_head() {
		if (empty($this->options['css']))
			return;
?>
<style type="text/css">
/* <![CDATA[ */
<?php echo $this->options['css']; ?>

/* ]]> */
</style>
<?php
	}


	// [graphviz
	//  id="id"
	//  lang="dot|neato|twopi|circo|fdp"
	//  simple="true|false"
	//  output="png|gif|jpg|svg"
	//  imap="true|false"
	//  href="url|self"
	// ]

	function _wrap_error($error) {
		return "<p class=\"graphviz_error\"><b>Error generating Graphviz image:</b> $error</p>\n";
	}
	/**
	 * Shortcode -> <img> markup.  Creates images as necessary.
	 *
	 * @param hash    $_atts Attributes given in the shortcode element.
	 * @param string  $dot   The DOT contents from inside the shortcode section.
	 * @return string The rendered HTML that the shortcode section is replaced with.
	 */
	function shortcode($_atts, $dot) {
		$atts = shortcode_atts(array(
				'id' => 'tfo_graphviz_'.($this->count++),
				'lang' => 'dot',
				'simple' => false,
				'digraph' => false,
				'graph' => false,
				'output' => 'png',
				'imap' => false,
				'href' => false,
				'title' => '',
				'width' => '',
				'height' => '',
				'size' => '',
				'dpi' => '',
				'emitjs' => TFO_GRAPHVIZ_EMITJS,
				'class' => '',
				'method' => '',
			), $_atts);

		$atts["lang"] = strtolower($atts["lang"]);
		if (!in_array($atts['lang'], $this->langs)) {
			$e = "Graph language <em>".esc_attr($atts['lang'])."</em> is unknown.";
			return $this->_wrap_error($e);
		}

		$atts["output"] = strtolower($atts["output"]);
		if (!in_array($atts['output'], $this->outputs)) {
			$e = "Graph output format <em>".esc_attr($atts['output'])."</em> is unknown.";
			return $this->_wrap_error($e);
		}

		if (TFO_GV_DEBUG) file_put_contents(sys_get_temp_dir() . "/pre-".$this->count.".dot", $dot);

		// Load some JavaScript to resize imagemaps if we have a scaled image,
		// image maps and we're allowing JavaScript emission.
		if ($atts['emitjs'] && $atts['imap'] && (!empty($atts['width']) || !empty($atts['height']))) {
			wp_enqueue_script('jquery');
			$url = plugins_url(basename(dirname(__FILE__)) . '/js/imageMapResizer.min.js');
			wp_enqueue_script('imageMapResizer', $url, array('jquery'), '1.0.10', true);
			$url = plugins_url(basename(dirname(__FILE__)) . '/js/tfo-gv.js');
			wp_enqueue_script('tfo-gv', $url, array('jquery', 'imageMapResizer'), '1.0.0', true);
			$url = false;
		}

		// WordPress mangles newlines into HTML <br /> and </p>'s
		$dot = preg_replace(array('#<br\s*/?>#i', '#<p>#i'), "", $dot);
		$dot = preg_replace(array('#</p>#i'), "\n", $dot);

		// Newer WordPress is HTML escaping the content
		$dot = html_entity_decode($dot);

		// Work out if the dot needs to be wrapped
		if ($atts['simple']) { // emulate eht-graphviz
			$dot = "digraph ".$atts['id']." {\n$dot\n}\n";
		} elseif ($atts['digraph']) {
			$dot = "digraph ".$atts['id']." {\n$dot\n}\n";
		} elseif ($atts['graph']) {
			$dot = "graph ".$atts['id']." {\n$dot\n}\n";
		}

		if (TFO_GV_DEBUG) file_put_contents(sys_get_temp_dir() . "/post-".$this->count.".dot", $dot);

		// Go render the graph!
		$gv = $this->graphviz($dot, $atts);
		if (!$gv) {
			$e = "Graphviz generation failed";
			if ($this->err) $e .= ': '.$this->err;
			else $e .= '.';
			return $this->_wrap_error($e);
		}

		if ($gv->emits_url()) {
			// Attempt to retrieve the rendered result
			$url = false;
			try {
				$url = $gv->url();
			} catch (Exception $e) {
				$e = "Graphviz generation failed";
				if ($this->err) $e .= ': '.$this->err;
				else $e .= '.';
				return $this->_wrap_error($e);
			}

			if (!is_wp_error($url)) {
				// Success, generate the img tags and any image map
				$url = esc_url($url);
				$href = $gv->href;
				if ($href) {
					if (strtolower($href) == 'self') $href = $url;
					else $href = esc_url($href);
				}
				$alt = esc_attr($gv->title);
				$ret = "<img src=\"$url\" class=\"graphviz";
				if (!empty($atts['class'])) $ret .= " ".esc_attr($atts['class']);
				$ret .= "\"";
				if (!empty($alt)) $ret .= " alt=\"$alt\" title=\"$alt\"";
				if (!empty($gv->imap)) $ret .= " usemap=\"#$gv->id\"";
				if (!empty($atts['width'])) $ret .= " width=\"".esc_attr($atts['width'])."\"";
				if (!empty($atts['height'])) $ret .= " height=\"".esc_attr($atts['height'])."\"";
				$ret .= " />";
				if (!empty($href)) $ret = "<a href=\"".$href."\">$ret</a>";
				if (!empty($gv->imap)) $ret .= "\n$gv->imap";

			} else {
				// An error, wrap any message we received in some HTML and return it
				$ret = "<pre>";
				$ret .= $url->get_error_message();
				$d = $url->get_error_data();
				if ($d) {
					$ret .= "\n";
					$ret .= $url->get_error_data();
				}
				$ret .= "</pre>\n";
				return $this->_wrap_error($ret);
			}
		} else if ($gv->emits_inline()) {
			// Go get the inline content
			$ret = $gv->inline();
		}

		return $ret;
	}


	/**
	 * Invokes the selected Graphviz method.
	 *
	 * @param string  $dot  The dot source text to use.
	 * @param string  $atts Attributes that control the generation.
	 * @return bool True on success, False otherwise.
	 */
	function &graphviz($dot, $atts) {
		$this->err = false;
		$gv_method = $this->options['method'];

		// Override the rendering method
		if (!empty($atts['method']))
			if (!empty($this->methods['TFO_Graphviz_' . $atts['method']])) {
				# Shorthand
				$gv_method = 'TFO_Graphviz_' . $atts['method'];
			} else {
				$gv_method = $atts['method'];
			}

		if (empty($this->methods[$gv_method])) {
			$this->err = 'Unknown method "'.$gv_method.'"';
			return false;
		}

		// Validate atts
		$atts['id'] = esc_attr($atts['id']);
		if ($atts['lang'] && !in_array($atts['lang'], $this->langs)) {
			$this->err = "Unknown lang: ".$atts['lang'];
			return false;
		}
		if ($atts['output'] && !in_array($atts['output'], $this->outputs)) {
			$this->err = "Unknown output: ".$atts['output'];
			return false;
		}

		$yes = array('true', 'yes', '1');
		foreach (array('simple', 'imap') as $att) {
			if ($atts[$att] && in_array(strtolower($atts[$att]), $yes)) $atts[$att] = true;
			else $atts[$att] = false;
		}

		if (!tfo_mkdir_p(TFO_GRAPHVIZ_CONTENT_DIR)) {
			$this->err = "Directory <code>".TFO_GRAPHVIZ_CONTENT_DIR."</code> is either not writable, not a directory or not creatable";
			return false;
		}

		require_once dirname( __FILE__ ).'/tfo-graphviz-'.$this->methods[$gv_method].'.php';
		if (!class_exists($gv_method)) {
			$gv_method = 'TFO_GraphViz_Method';
		}
		$gv_object = new $gv_method($dot, $atts, TFO_GRAPHVIZ_CONTENT_DIR, TFO_GRAPHVIZ_CONTENT_URL);
		if (!$gv_object) {
			$this->err = "Unable to create Graphviz renderer, check your plugin settings";
			return false;
		}

		return $gv_object;
	}

}


if (!function_exists('tfo_mkdir_p')) :


	/**
	 * Simple helper to mkdir a directory and all missing parent directories.
	 *
	 * @param string  $target Directory to mkdir.
	 * @return bool True on success, False otherwise.
	 */
	function tfo_mkdir_p($target) {
		// modified from php.net/mkdir user contributed notes
		if (file_exists($target)) {
			if (!@is_dir($target) || !@is_writable($target))
				return false;
			else
				return true;
		}

		// Attempting to create the directory may clutter up our display.
		if (@mkdir($target)) {
			$stat = @stat(dirname($target));
			$dir_perms = $stat['mode'] & 0007777;  // Get the permission bits.
			@chmod($target, $dir_perms);
			return true;
		} else {
			if (is_dir(dirname($target)))
				return false;
		}

		// If the above failed, attempt to create the parent node, then try again.
		if (tfo_mkdir_p(dirname($target)))
			return tfo_mkdir_p($target);

		return false;
	}


endif;

if (is_admin()) {
	require dirname(__FILE__).'/tfo-graphviz-admin.php';
	$tfo_graphviz = new TFO_Graphviz_Admin;
	register_activation_hook(__FILE__, array(&$tfo_graphviz, 'activation_hook'));
} else {
	$tfo_graphviz = new TFO_Graphviz;
}

add_action('init', array( &$tfo_graphviz, 'init'));
?>

<?php
/**
 * tfo-graphviz-graphviz.php
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


// $Id: tfo-graphviz-graphviz.php 2110713 2019-06-22 22:26:38Z chrisy $

/*
Must define the following constants:
TFO_GRAPHVIZ_GRAPHVIZ_PATH
*/

require_once dirname(__FILE__).'/tfo-graphviz-method.php';

if (!defined('TFO_GRAPHVIZ_GRAPHVIZ_PATH')) {
	$tfo_include_error = "'TFO_GRAPHVIZ_GRAPHVIZ_PATH' is not defined";
	return FALSE;
}

if (!file_exists(TFO_GRAPHVIZ_GRAPHVIZ_PATH)) {
	$tfo_include_error = "'TFO_GRAPHVIZ_GRAPHVIZ_PATH' points to file '" . TFO_GRAPHVIZ_GRAPHVIZ_PATH . "' which does not exist";
	return FALSE;
}

class TFO_Graphviz_Graphviz extends TFO_Graphviz_Method {
	var $tmp_file;
	var $img_path_base;
	var $img_url_base;
	var $file;


	/**
	 * Constructor implementation.
	 *
	 * @param string  $dot           Type of Graphviz source.
	 * @param hash    $atts          List of attributes for Graphviz generation.
	 * @param string  $img_path_base (optional) Directory path for image generation (optional)
	 * @param string  $img_url_base  (optional) URL that points to $img_path_base (optional)
	 */
	function __construct($dot, $atts, $img_path_base=null, $img_url_base=null) {
		parent::__construct($dot, $atts);
		$this->img_path_base = rtrim( $img_path_base, '/\\' );
		$this->img_url_base = rtrim( $img_url_base, '/\\' );

		// For PHP 4
		if (version_compare( PHP_VERSION, 5, '<'))
			register_shutdown_function(array(&$this, '__destruct'));
	}


	/**
	 * Destructor.
	 */
	function __destruct() {
		$this->unlink_tmp_files();
	}


	/**
	 * Returns a hash of the contents of the current source file.
	 * Requires the file already be loaded into $this->dot .
	 *
	 * @return string The hash.
	 */
	function hash_file() {
		// Concatenate all the options for execution
		// Otherwise when they change we may not regenerate the output
		$data = sprintf("%s\n%%%d-%s-%s-%s%%\n",
			$this->dot,
			$this-imap,
			$this->lang,
			$this->size,
			$this->dpi);

		$hash = md5($data);
		return substr($hash, 0, 32);
	}


	/**
	 * Processes the Graphviz file.
	 *
	 * @param string  $imgfile Name of the file in which to store the generated image.
	 * @param string  $mapfile Name of the file in which to store any generated image map.
	 * @return bool True on success, False otherwise.
	 */
	function process_dot($imgfile, $mapfile) {
		if (!defined('TFO_GRAPHVIZ_GRAPHVIZ_PATH') || !file_exists(TFO_GRAPHVIZ_GRAPHVIZ_PATH))
			return new WP_Error('graphviz_path', __('Graphviz path not specified, is wrong or binary is missing.', 'tfo-graphviz'));

		if (empty($this->dot))
			return new WP_Error('blank', __('No graph provided', 'tfo-graphviz'));

		$args = array(
			'-K'.$this->lang,
		);
		if ($this->imap) {
			array_push($args,
				'-Tcmapx',
				'-o'.$mapfile
			);
		}
		array_push($args,
			'-T'.$this->output,
			'-o'.$imgfile
		);

		if (!empty($this->size)) {
			array_push($args, "-Gsize=".$this->size);
		}

		if (!empty($this->dpi)) {
			array_push($args, "-Gdpi=".$this->dpi);
		}

		$cmd = TFO_GRAPHVIZ_GRAPHVIZ_PATH;
		foreach ($args as $arg) {
			$cmd .= ' '.escapeshellarg($arg);
		}

		$ds = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = false;
		$proc = proc_open($cmd, $ds, $pipes, sys_get_temp_dir(), array());
		$out = ''; $err = '';
		if (is_resource($proc)) {
			fwrite($pipes[0], $this->dot);
			fclose($pipes[0]);

			$out = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[2]);

			proc_close($proc);
		} else {
			return new WP_Error('graphviz_exec', __( 'Graphviz cannot generate graph', 'tfo-graphviz' ));
		}

		if (!file_exists($imgfile) || ($this->imap && !file_exists($mapfile))) {
			$dot = '';
			$num = 1;
			foreach (explode("\n", $this->dot) as $line) {
				$dot .= sprintf("%5d %s\n", $num++, $line);
			}
			return new WP_Error('graphviz_exec', __( 'Graphviz cannot generate graph', 'tfo-graphviz' ),
				"Command: $cmd\nOutput: \n$out$err\nOriginal DOT:\n".$dot);
		}

		return true;
	}


	/**
	 * Cleans up any temporary files.
	 *
	 * @return bool True on success, False otherwise.
	 */
	function unlink_tmp_files() {
		if ( TFO_GV_DEBUG )
			return;

		if ( !$this->tmp_file )
			return false;

		@unlink( $this->tmp_file );

		return true;
	}


	/**
	 * Calculates the URL for the resulting image; this processes the DOT file if necessary.
	 *
	 * @return bool True on success, False otherwise.
	 */
	function url() {
		if ( !$this->img_path_base || !$this->img_url_base ) {
			$this->error = new WP_Error( 'img_url_base', __( 'Invalid path or URL' ) );
			return $this->error;
		}

		$hash = $this->hash_file();

		$imgfile = $this->img_path_base.'/'.$hash.'.'.$this->output;
		$mapfile = $this->img_path_base.'/'.$hash.'.map';
		if (is_super_admin() || !file_exists($imgfile) || ($this->imap && !file_exists($mapfile))) {
			$ret = $this->process_dot($imgfile, $mapfile);
			if ( is_wp_error( $ret ) ) {
				$this->error = $ret;
				return $this->error;
			}
		}

		$this->file = $imgfile;
		$this->url = $this->img_url_base.'/'.$hash.'.'.$this->output;
		if ($this->imap) {
			if (file_exists($mapfile)) $this->imap = file_get_contents($mapfile);
			else $this->imap = false;
		}
		return $this->url;
	}

	function emits_url() {
		return true;
	}
}


return TRUE;

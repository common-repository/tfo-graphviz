<?php
/**
 * tfo-graphviz-graphlib-dot.php
 *
 * @package tfo-graphviz
 *
 * TFO-Graphviz WordPress plugin
 * Copyright (C) 2019 Chris Luke <chrisy@flirble.org>
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


// $Id: tfo-graphviz-graphlib-dot.php 1286864 2015-11-16 03:36:55Z chrisy $

/*
Must define the following constants:
TFO_GRAPHVIZ_GRAPHVIZ_PATH
*/

require_once dirname(__FILE__).'/tfo-graphviz-method.php';

class TFO_Graphviz_Graphlib_Dot extends TFO_Graphviz_Method {
	/**
	 * Constructor implementation.
	 *
	 * @param string  $dot           Type of Graphviz source.
	 * @param hash    $atts          List of attributes for Graphviz generation.
	 */
	function __construct($dot, $atts) {
		parent::__construct($dot, $atts);

		wp_enqueue_script('jquery');

		$url = "https://d3js.org/d3.v4.js";
		wp_enqueue_script('d3', $url, array(), '4.0.0', true);

		$url = plugins_url(basename(dirname(__FILE__)) . '/js/dagre-d3.min.js');
		wp_enqueue_script('dagre-d3', $url, array('d3'), '0.3.0', true);

		$url = plugins_url(basename(dirname(__FILE__)) . '/js/graphlib-dot.min.js');
		wp_enqueue_script('graphlib-dot', $url, array('d3'), '0.6.1', true);

		$url = plugins_url(basename(dirname(__FILE__)) . '/css/tfo_gv_d3.css');
		wp_enqueue_style('tfo_gv_d3', $url, array(), '0.0.1');

	}


	function emits_inline() {
		return true;
	}


	function inline() {
		// Work out what HTML we need to emit to generate the graph

		$id = $this->id;  // TODO escape it so it's a valid html id
		$id = "_$id";

		$dot = $this->dot; // TODO need to js-escape the src dot
		$dot = str_replace('\\', '\\\\', $dot);
		$dot = str_replace('"', '\\"', $dot);
		$dot = str_replace("\n", "\\n", $dot);
		$dot = str_replace("\r", "", $dot);

		# The graphics element
		$ret = "<svg id=\"gv_svg$id\"";
		if (!empty($this->width)) $ret .= " width=\"".esc_attr($this->width)."\"";
		if (!empty($this->height)) $ret .= " height=\"".esc_attr($this->height)."\"";
		$ret .= " class=\"tfo_gv\"><g/></svg>\n";

		$js = "<script>\n";
		$js .= "function d3load$id() {\n";
		$js .= "  var svg = d3.select(\"svg#gv_svg$id\"),\n";
		$js .= "    inner = d3.select(\"svg#gv_svg$id g\"),\n";
		$js .= "    zoom = d3.zoom().on(\"zoom\",\n";
		$js .= "    function() {\n";
		$js .= "      inner.attr(\"transform\", d3.event.transform);\n";
		$js .= "  }); \n";
		$js .= "  svg.call(zoom);\n\n";

		$js .= "  var dotstr = \"$dot\";\n";
		$js .= "  var dotgraph = graphlibDot.read(dotstr);\n";
		$js .= "  var render = dagreD3.render();\n";
		$js .= "  d3.select(\"svg#gv_svg$id g\").call(render, dotgraph);\n";
		$js .= "}\n\n";

		$js .= "jQuery(document).ready(d3load$id);\n";
		$js .= "</script>\n";

		$ret .= $js;

		return $ret;
	}
}


return TRUE;

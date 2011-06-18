<?php

class template {

	protected $loopArrays = array();
	protected $templateVars = array();
	protected $html;
	protected $revertHtml;
	protected $streaming = false;
	
	private $tt = "etl:"; #markup tag
	
	function __construct($templateName) {
		if (!file_exists($templateName)) {
			print "Error! template: $templateName not found\n";
			exit;
		}
		## note that using file_get_contents allows for remote storage of templates
		## via web or ftp server
		$this->html = file_get_contents($templateName);
		$this->revertHtml = $this->html;
	}
	
	function setStreaming() {
		# if this is set, draw page up to first $tt tag every time registerVar or registerLoop is set.
		$this->streaming = true;
	}
	
	function revert() {
		## reset html to original, unparsed value
		$this->html = $this->revertHtml;
	}
	
	function registerLoop($dataArray, $name) {
		/*
		* register loop expects an object that is an array of 
		* associative arrays
		* 
		* unlike registerVar, registerLoop will replace ANY variable tag within it,
		* even if there is no corresponding index in the current row
		* non-initialized variable tags will be replaced with the php string value
		* of uninitialized variables, usually ''
		*
		*/
		
		$dataArray = $this->objectToArray($dataArray);
		
		$this->loopArrays[$name] = &$dataArray;
		if ($this->streaming) {
			$this->streamOut();
		}
	}
	
    /**
    *
    * Convert an object to an array
    *
    * @param    object  $object The object to convert
    * @reeturn      array
    *
    */
    function objectToArray( $object ) {
		if(is_array($object) || is_object($object)) {
			$array = array();
			
			foreach($object as $key => $value) {
				$array[$key] = $this->objectToArray($value);
			}
			
			return $array;
		}
		
		return $object;
	}
	
	function registerVar($var, $name) {
		/*
		* register var replaces any variable of the form {$tt: varName} with the $var value
		* as long as that variable is outside a {loop} construct.  Loops and vars have distinct
		* name spaces
		*/
		$this->templateVars[$name] = $var;
		if ($this->streaming) {
			$this->streamOut();
		}
	}
	
	protected function streamOut() {
		$this->parseTemplate();
		if (strpos($this->html, "{{$this->tt}") !== false) {
			$explodePoint = strpos($this->html, "{{$this->tt}");

			$topHtml = substr($this->html, 0, $explodePoint);
			$this->html = substr($this->html, $explodePoint);
			print $topHtml;
		}
		else {
			print $this->html;
		}
	}

	protected function replaceVar ($name, $val, $text) {
		$text = str_replace("{{$this->tt} $name}", $val, $text);
		return $text;
	}
	
	
	protected function parseTemplate() {
		$this->html = $this->loops($this->html);
		$this->html = $this->parseIf($this->html);
		foreach($this->templateVars as $key => $val) {
			$this->html = $this->replaceVar($key, $val, $this->html);
		}
	}
	
	function printToServer() {
		#usually the last public member function called, asserts to stdout
		$this->parseTemplate();
		print $this->html;
	}
	
	function printToStr() {
		$this->parseTemplate();
		return $this->html;
	}
	
	protected function parseIf($iftext) {
		/*
		* if statements within loops must be initialized within the array object.  As
		* mentioned above, there is not overlap between the loop and var name space
		*/
		while (strpos($iftext, "{{$this->tt} if ") !== false) {
			$ifstart = strpos($iftext, "{{$this->tt} if ");
			if (!($closing = strpos($iftext, "}", $ifstart))) {
				print "If syntax error 1\n";
				exit;
			}
			$ifname = substr($iftext, $ifstart + strlen("{{$this->tt} if "), $closing - $ifstart - strlen("{{$this->tt} if "));
			if (!($ifend = strpos($iftext, "{/$this->tt if $ifname}", $ifstart))) {
				print "If syntax error 2: $ifname\n";
				exit;
			}
			$falsetext = "";
			$ifBlock = substr($iftext, $ifstart + strlen("{{$this->tt} if $ifname}"), $ifend - $ifstart - strlen("{{$this->tt} if $ifname}"));
			@list($truetext, $falsetext) = explode("{{$this->tt} else $ifname}", $ifBlock);
			if ($this->streaming) {
				#if streaming, only do ifs whose variables exist
				if ($this->templateVars[$ifname]) {
					#case TRUE.  Remove tags and put in true block				
					$iftext = substr_replace($iftext, $truetext, $ifstart, $ifend - $ifstart + strlen("{/$this->tt if $ifname}"));
				}
				else if (isset($this->templateVars[$ifname])) {
					#case FALSE. Remove whole block and put in else block if exists
					$iftext = substr_replace($iftext, $falsetext, $ifstart, $ifend - $ifstart + strlen("{/$this->tt if $ifname}"));
				}
			}
			else {
				#if streaming, only do ifs whose variables exist
				if ($this->templateVars[$ifname]) {
					#case TRUE.  Remove tags and put in true block				
					$iftext = substr_replace($iftext, $truetext, $ifstart, $ifend - $ifstart + strlen("{/$this->tt if $ifname}"));
				}
				else {
					#case FALSE. Remove whole block and put in else block if exists
					$iftext = substr_replace($iftext, $falsetext, $ifstart, $ifend - $ifstart + strlen("{/$this->tt if $ifname}"));
				}
				
			}
		}
		return $iftext;
	}
	
	protected function parseIfLoop($iftext, $rowData) {

		while (strpos($iftext, "{{$this->tt} if ") !== false) {
			$ifstart = strpos($iftext, "{{$this->tt} if ");
			if (!($closing = strpos($iftext, "}", $ifstart))) {
				print "If syntax error 1\n";
				exit;
			}
			$ifname = substr($iftext, $ifstart + strlen("{{$this->tt} if "), $closing - $ifstart - strlen("{{$this->tt} if "));
			if (!($ifend = strpos($iftext, "{/$this->tt if $ifname}", $ifstart))) {
				print "If syntax error 2: $ifname\n";
				exit;
			}
			#inside of loop, if variable name doesn't exist, then leave the if where it is?
			if (!isset($rowData[$ifname])) {
				#not a defined variable.  Assume global scope and save if for later
				$iftext = str_replace("{{$this->tt} if $ifname}", "{~{$this->tt} if $ifname}", $iftext);
			}
			else {
				$falsetext = "";
				$ifBlock = substr($iftext, $ifstart + strlen("{{$this->tt} if $ifname}"), $ifend - $ifstart - strlen("{{$this->tt} if $ifname}"));
				list($truetext, $falsetext) = explode("{{$this->tt} else $ifname}", $ifBlock);
				if ($rowData[$ifname]) {
					#case TRUE.  Remove tags and put in true block				
					$iftext = substr_replace($iftext, $truetext, $ifstart, $ifend - $ifstart + strlen("{/{$this->tt} if $ifname}"));
				}
				else {
					#case FALSE. Remove whole block and put in else block if exists
					$iftext = substr_replace($iftext, $falsetext, $ifstart, $ifend - $ifstart + strlen("{/{$this->tt} if $ifname}"));
				}
			}
		}
		$iftext = str_replace("{~{$this->tt}", "{{$this->tt}", $iftext);
		return $iftext;
	}
	
	protected function loops($looptext) {
		/*
		*
		* loops support header and footer tags. 
		* the header and footer will only be drawn if the loop has 1 or more
		* rows.
		*
		*/
		$loopname = "";
		while (strpos($looptext, "{{$this->tt} loop ") !== false) {
		
		    $loopstart = strpos($looptext, "{{$this->tt} loop ");
			$header = "";
			$footer = "";
			if (!($closing = strpos($looptext, "}", $loopstart))) {
				print "loop syntax error 1\n";
				exit;
			}
			$loopname = substr($looptext, $loopstart + strlen("{{$this->tt} loop "), $closing - $loopstart - strlen("{{$this->tt} loop "));
			if (!($loopend = strpos($looptext, "{/$this->tt loop $loopname}", $loopstart))) {
				print "loop syntax error 2\n";
				exit;
			}
	
			$loopbody = substr($looptext, $loopstart + strlen("{{$this->tt} loop $loopname}"), $loopend - $loopstart - strlen("{{$this->tt} loop $loopname}"));
	
			## pull out header
			if (($headstart = strpos($loopbody, "{{$this->tt} header $loopname}")) > 0) {
				#there's a header in this loop
				if (!($headend = strpos($loopbody, "{/$this->tt header $loopname}"))) {
					print "Header syntax error in loop $loopname\n";
					exit;
				}
				$header = substr($loopbody, $headstart + strlen("{{$this->tt} header $loopname}"), $headend - $headstart - strlen("{{$this->tt} header $loopname}"));
				$loopbody = substr_replace($loopbody, '', $headstart, $headend + strlen("{/$this->tt header $loopname}") - $headstart);
			}
	
			#pull out footer
			if (($footstart = strpos($loopbody, "{{$this->tt} footer $loopname}")) > 0) {
				#there's a footer in this loop
				if (!($footend = strpos($loopbody, "{/$this->tt footer $loopname}"))) {
					print "Footer syntax error in loop $loopname\n";
					exit;
				}
				$footer = substr($loopbody, $footstart + strlen("{{$this->tt} footer $loopname}"), $footend - $footstart - strlen("{{$this->tt} footer $loopname}"));
				$loopbody = substr_replace($loopbody, '', $footstart, $footend + strlen("{/$this->tt footer $loopname}") - $footstart);
			}
	
			# run through the loop and replace variables.
			$loopresult = "";


			if (!empty($this->loopArrays[$loopname])) {
				$loopresult .= $header;
				foreach ($this->loopArrays[$loopname] as $row) {
					$loopholding = $loopbody;
					$loopholding = $this->parseIfLoop($loopholding, $row);
					#replace variables within this block, yet somehow avoid any variables in any
					# inner loops.
					
					##alert!  preg
					while (preg_match("/\{$this->tt \w+\}/", $loopholding, $matches)) {
						$key = $matches[0];
	 					$key = substr($key, strlen("{{$this->tt} "), strpos($key, "}") - strlen("{{$this->tt} "));
						$loopholding = $this->replaceVar($key, $row[$key], $loopholding);
					}			
					$loopresult .= $loopholding;
				}
				$loopresult .= $footer;
			}
			if (!empty($this->streaming)) {
				#if streaming, only print loops that have their variable set.
				if(isset($this->loopArrays[$loopname])) {
					$looptext = substr_replace($looptext, $loopresult, $loopstart, $loopend + strlen("{/$this->tt loop $loopname}") - $loopstart);
				}
				else {
					$looptext = str_replace("{{$this->tt} loop $loopname}", "{~{$this->tt}~ loop $loopname}", $looptext);
				}
			}
			else {
				$looptext = substr_replace($looptext, $loopresult, $loopstart, $loopend + strlen("{/$this->tt loop $loopname}") - $loopstart);
				
			}
		}
		
		#if streaming, restore temp placeholders for next pass
		if (!empty($this->streaming)) {
				$looptext = str_replace("{~{$this->tt}~ loop $loopname}", "{{$this->tt} loop $loopname}", $looptext);
		}
		
		return $looptext;
	}
	
}

?>

<?php

/*
 * The MIT License
 *
 * Copyright 2015 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace import;

/**
 * Represents a token.
 * In tokeneditor every document is as set of tokens and each token has the same
 * set of properties (but of course property values may differ among tokens).
 * 
 * It is implemented on top of the \DOMElement class.
 * 
 * @author zozlak
 */
class Token {
	/**
	 *
	 * @var \PDOStatement
	 */
	private static $valuesQuery = null;
	
	/**
	 *
	 * @var DomElement
	 */
	private $dom;
	/**
	 *
	 * @var Document
	 */
	private $document;
	private $tokenId;
	private $properties = array();
	
	/**
	 * If the $dom->ownerDocument is a large \DOMDocument, please consider
	 * detaching $dom from it before passing it to the constructor.
	 * 
	 * \DOMXPath is used internally to search for token's property values and as 
	 * it can operate on \DOMDocument only, it is using $dom->ownerDocument. if 
	 * this \DOMDocument is large, \DOMXPath will be slow, no matter how small 
	 * and simple \DOMElement you are passing in $dom is.
	 * 
	 * Detaching is simple:
	 *   $tmpDoc = new \DOMDocument();
	 *   new Token($tmpDoc->importNode($dom, true), $myDoc);
	 * 
	 * What is important, detached tokens will still synchronize their content 
	 * with parent document (by calling \import\Document->replaceToken())
	 * 
	 * @param type $xml
	 * @param \import\Schema $schema
	 * @throws \LengthException
	 */
	public function __construct(\DOMElement $dom, Document $document){
		$this->dom = $dom;
		$this->document = $document;
		$this->tokenId = $this->document->generateTokenId();
		
		$xpath = new \DOMXPath($dom->ownerDocument);
		foreach($this->document->getSchema() as $prop){
			try{
				$value = $xpath->query($prop->getXPath(), $dom);
				if($value->length != 1){
					throw new \LengthException('property not found or many properties found');
				}
				$this->properties[$prop->getXPath()] = $value->item(0);
			}catch (\LengthException $e){}
		}
	}

	/**
	 * Saves token in the database
	 * (db connection handle is taken from parent document)
	 */
	public function save(){
		$PDO = $this->document->getPDO();
		$docId = $this->document->getId();
		
		$query = $PDO->prepare("INSERT INTO tokens (document_id, token_id, value) VALUES (?, ?, ?)");
		$query->execute(array($docId, $this->tokenId, $this->dom->nodeValue));
		
		$query = $PDO->prepare("INSERT INTO orig_values (document_id, token_id, property_xpath, value) VALUES (?, ?, ?, ?)");
		foreach ($this->properties as $xpath => $prop){
			$value = isset($prop->value) ? $prop->value : $prop->nodeValue;
			$query->execute(array($docId, $this->tokenId, $xpath, $value));
		}
	}
	
	/**
	 * 
	 */
	public function update(){
		$this->checkValuesQuery();
		
		foreach($this->properties as $xpath => $prop){
			self::$valuesQuery->execute(array($this->document->getId(), $xpath, $this->tokenId));
			$value = self::$valuesQuery->fetch(\PDO::FETCH_OBJ);
			if($value !== false){
				if(isset($prop->value)){
					$prop->value = $value->value;
				}else{
					$prop->nodeValue = $value->value;
				}
			}
		}
		$this->document->getIterator(false)->replaceToken($this->tokenId - 1, $this->dom);
	}
	
	/**
	 * 
	 */
	public function enrich(){
		$this->checkValuesQuery();
		foreach($this->properties as $xpath => $prop){
			self::$valuesQuery->execute(array($this->document->getId(), $xpath, $this->tokenId));
			while($value = self::$valuesQuery->fetch(\PDO::FETCH_OBJ)){
				$user  = $this->createTeiFeature('user', $value->user_id);
				$date  = $this->createTeiFeature('date', $value->date);
				$xpath = $this->createTeiFeature('property_xpath', $xpath);
				$val   = $this->createTeiFeature('value', $value->value);
				$fs    = $this->createTeiFeatureSet();
				$fs->appendChild($user);
				$fs->appendChild($date);
				$fs->appendChild($xpath);
				$fs->appendChild($val);
				if($prop->nodeType !== XML_ELEMENT_NODE){
					$prop->parentNode->appendChild($fs);
				}else{
					$prop->appendChild($fs);
				}
			}
		}
		$this->document->getIterator(false)->replaceToken($this->tokenId - 1, $this->dom);
	}

	private function checkValuesQuery(){
		if(self::$valuesQuery === null){
			self::$valuesQuery = $this->document->getPDO()->
				prepare("SELECT user_id, value, date FROM values WHERE (document_id, property_xpath, token_id) = (?, ?, ?) ORDER BY date DESC");
		}
	}
	
	/**
	 * Creates a \DOMNode representing TEI feature set
	 * (as it is a lot of boilerplate code)
	 *  
	 * @return \DOMNode
	 */
	private function createTeiFeatureSet(){
		$doc = $this->dom->ownerDocument;
		
		$type = $doc->createAttribute('type');
		$type->value = 'tokeneditor';

		$fs = $doc->createElement('fs');
		$fs->appendChild($type);
		
		return($fs);
	}
	
	/**
	 * 
	 * @param string $name
	 * @param string $value
	 * @return \DOMNode
	 */
	private function createTeiFeature($name, $value){
		$doc = $this->dom->ownerDocument;
		
		$fn = $doc->createAttribute('name');
		$fn->value = $name;
		
		$v = $doc->createElement('string', $value);
		
		$f = $doc->createElement('f');
		$f->appendChild($fn);
		$f->appendChild($v);
		
		return $f;
	}
}

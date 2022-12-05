<?php

namespace icy8\WebSpider\selectors;

use DOMDocument;
use DOMXPath;

class Xpath implements Selector
{
    protected $data  = null;
    protected $isXml = false;

    public function __construct($data = '')
    {
        if (!empty($data)) {
            $this->setData($data);
        }
    }

    public function setData($data, $isXml = false)
    {
        $this->data  = $data;
        $this->isXml = $isXml;
        return $this;
    }

    public function select($rule, $raw = false)
    {
        $dom = new DOMDocument();
        if ($this->isXml) {
            @$dom->loadXML($this->data);
        } else {
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $this->data);
        }
        $xpath    = new DOMXPath($dom);
        $elements = $xpath->query($rule);
        $result   = [];
        foreach ($elements as $item) {
            $nodeType = $item->nodeType;
            $nodeName = $item->nodeName;
            $content  = '';
            if ($raw) {
                if ($this->isXml) {
                    $content = $dom->saveXML($item);
                } else {
                    $content = $dom->saveHTML($item);
                }
            } else if ($nodeName == 'img') {
                $content = $item->getAttributeNode('src')->value;
            } else if (in_array($nodeType, [\XML_ATTRIBUTE_NODE, \XML_TEXT_NODE, \XML_CDATA_SECTION_NODE])) {
                $content = $item->nodeValue;
            } else {
                foreach ($item->childNodes as $node) {
                    if ($this->isXml) {
                        $content .= $dom->saveXML($node);
                    } else {
                        $content .= $dom->saveHTML($node);
                    }
                }
            }
            $result[] = $content;
        }
        return $result;
    }

    public function fetch($rule)
    {
        return current($this->select($rule));
    }

    public static function shortcut()
    {
        return new static(...func_get_args());
    }
}

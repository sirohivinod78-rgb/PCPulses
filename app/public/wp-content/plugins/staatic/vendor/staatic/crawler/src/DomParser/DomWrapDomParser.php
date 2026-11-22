<?php

namespace Staatic\Crawler\DomParser;

use Staatic\Vendor\DOMWrap\Document;
use Staatic\Vendor\DOMWrap\ProcessingInstruction;
use DOMXPath;
final class DomWrapDomParser implements DomParserInterface
{
    private const HTML_PARSE_RECOVER = 1;
    /**
     * @param string $html
     */
    public function documentFromHtml($html)
    {
        $document = new Document();
        $document->loadHTML($html, \LIBXML_NOERROR | self::HTML_PARSE_RECOVER);
        return $document;
    }
    public function getHtml($document): string
    {
        $document->contents()->each(function ($node) {
            if ($node instanceof ProcessingInstruction && $node->nodeName == 'xml') {
                $node->destroy();
            }
        });
        $rawTexts = $this->replaceNonAsciiRawTextsWithTokens($document);
        $html = $document->saveHTML();
        foreach ($rawTexts as [$element, $text]) {
            $element->textContent = $text;
        }
        return strtr($html, array_map(function (array $rawText) {
            return $rawText[1];
        }, $rawTexts));
    }
    private function replaceNonAsciiRawTextsWithTokens(Document $document): array
    {
        if (!$this->declaresUtf8OnlyWithMetaCharset($document)) {
            return [];
        }
        $rawTexts = [];
        $tokenPrefix = 'staatic-raw-text-' . bin2hex(random_bytes(8)) . '-';
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script | //style') as $index => $element) {
            $text = $element->textContent;
            if (!preg_match('/[\x80-\xFF]/', $text)) {
                continue;
            }
            $token = $tokenPrefix . $index . '-';
            $element->textContent = $token;
            $rawTexts[$token] = [$element, $text];
        }
        return $rawTexts;
    }
    private function declaresUtf8OnlyWithMetaCharset(Document $document): bool
    {
        $declaresUtf8 = \false;
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (strcasecmp(trim($meta->getAttribute('http-equiv')), 'content-type') === 0) {
                return \false;
            }
            if (in_array(strtolower(trim($meta->getAttribute('charset'))), ['utf-8', 'utf8'], \true)) {
                $declaresUtf8 = \true;
            }
        }
        return $declaresUtf8;
    }
    /**
     * @param string $name
     */
    public function hasAttribute($element, $name): bool
    {
        return $element->hasAttribute($name);
    }
    /**
     * @param string $name
     */
    public function getAttribute($element, $name): string
    {
        return $element->getAttribute($name);
    }
    /**
     * @param string $name
     * @param string $value
     */
    public function setAttribute($element, $name, $value): void
    {
        $element->setAttribute($name, $value);
    }
    /**
     * @param string $name
     */
    public function removeAttribute($element, $name): void
    {
        $element->removeAttribute($name);
    }
    public function getText($element): string
    {
        return $element->textContent;
    }
    /**
     * @param string $value
     */
    public function setText($element, $value): void
    {
        $element->textContent = $value;
    }
    public function getOuterHtml($element): string
    {
        return (string) $element;
    }
}

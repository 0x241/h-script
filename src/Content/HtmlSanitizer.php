<?php

namespace HScript\Content;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use HScript\Security\SafeUrl;

/**
 * Sanitizes administrator-authored rich text before persistence.
 */
final class HtmlSanitizer
{
	private const MAX_BYTES = 262144;
	private const MAX_NODES = 4096;
	private const MAX_DEPTH = 32;
	private const ALLOWED_TAGS = array(
		'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'figure', 'figcaption', 'h2', 'h3', 'h4',
		'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'small', 'span', 'strong', 'table', 'tbody', 'td',
		'tfoot', 'th', 'thead', 'tr', 'u', 'ul'
	);
	private const GLOBAL_ATTRIBUTES = array('class', 'title');
	private const TAG_ATTRIBUTES = array(
		'a' => array('href', 'target', 'rel'),
		'img' => array('src', 'alt', 'width', 'height'),
		'td' => array('colspan', 'rowspan'),
		'th' => array('colspan', 'rowspan', 'scope'),
	);

	public static function sanitize(string $html): string
	{
		if ($html === '')
			return '';
		if (strlen($html) > self::MAX_BYTES || !preg_match('//u', $html))
			return self::plainFallback($html);
		if (!class_exists(DOMDocument::class))
			throw new \RuntimeException('The PHP DOM extension is required to sanitize rich text safely');

		$document = new DOMDocument('1.0', 'UTF-8');
		$previousErrors = libxml_use_internal_errors(true);
		$document->loadHTML(
			'<!doctype html><html><head><meta charset="UTF-8"></head><body>'
				. '<div id="hs-sanitize-root">' . $html . '</div></body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrors);

		$root = $document->getElementById('hs-sanitize-root');
		if (!$root)
			return '';
		if (!self::withinStructureLimits($root))
			return self::plainFallback($html);
		self::sanitizeChildren($root);

		$result = '';
		foreach ($root->childNodes as $child)
			$result .= $document->saveHTML($child);
		return self::unwrapLanguageMarkerParagraphs($result);
	}

	private static function plainFallback(string $html): string
	{
		return htmlspecialchars(substr($html, 0, self::MAX_BYTES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function withinStructureLimits(DOMNode $root): bool
	{
		$stack = array(array($root, 0));
		$count = 0;
		while ($stack)
		{
			[$node, $depth] = array_pop($stack);
			if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH)
				return false;
			foreach ($node->childNodes as $child)
				$stack[] = array($child, $depth + 1);
		}
		return true;
	}

	/**
	 * CKEditor requires block-level root content and therefore wraps standalone
	 * language markers in paragraphs. Keep the application's marker syntax
	 * stable when rich text is saved or prepared for rendering.
	 */
	private static function unwrapLanguageMarkerParagraphs(string $html): string
	{
		$normalized = preg_replace(
			'~<p>(?:\s|&nbsp;|\x{00A0})*(\{![a-z0-9_-]{0,16}!\})(?:\s|&nbsp;|\x{00A0})*</p>~iu',
			'$1',
			$html
		);
		return $normalized === null ? $html : $normalized;
	}

	private static function sanitizeChildren(DOMNode $parent): void
	{
		foreach (iterator_to_array($parent->childNodes) as $node)
		{
			if ($node instanceof DOMComment)
			{
				$parent->removeChild($node);
				continue;
			}
			if (!($node instanceof DOMElement))
				continue;

			$tag = strtolower($node->tagName);
			if (!in_array($tag, self::ALLOWED_TAGS, true))
			{
				if (in_array($tag, array('script', 'style', 'iframe', 'object', 'embed', 'form'), true))
				{
					$parent->removeChild($node);
					continue;
				}
				self::sanitizeChildren($node);
				while ($node->firstChild)
					$parent->insertBefore($node->firstChild, $node);
				$parent->removeChild($node);
				continue;
			}

			self::sanitizeAttributes($node, $tag);
			if ($tag === 'img' && !$node->hasAttribute('src'))
			{
				$parent->removeChild($node);
				continue;
			}
			self::sanitizeChildren($node);
		}
	}

	private static function sanitizeAttributes(DOMElement $element, string $tag): void
	{
		$allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? array());
		foreach (iterator_to_array($element->attributes) as $attribute)
		{
			$name = strtolower($attribute->name);
			if (!in_array($name, $allowed, true) || str_starts_with($name, 'on'))
			{
				$element->removeAttribute($attribute->name);
				continue;
			}
			if ($name === 'href' && !SafeUrl::href($attribute->value))
				$element->removeAttribute($attribute->name);
			if ($name === 'src' && !SafeUrl::image($attribute->value))
				$element->removeAttribute($attribute->name);
		}
		if ($tag === 'a' && !in_array($element->getAttribute('target'), array('', '_blank', '_self'), true))
			$element->removeAttribute('target');
		if ($tag === 'a' && $element->getAttribute('target') === '_blank')
			$element->setAttribute('rel', 'noopener noreferrer');
	}
}

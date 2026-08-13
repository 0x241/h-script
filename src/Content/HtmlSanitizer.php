<?php

namespace HScript\Content;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitizes administrator-authored rich text before persistence.
 */
final class HtmlSanitizer
{
	private const ALLOWED_TAGS = array(
		'a', 'blockquote', 'br', 'code', 'div', 'em', 'figure', 'figcaption', 'h2', 'h3', 'h4',
		'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'span', 'strong', 'table', 'tbody', 'td',
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
		if (!class_exists(DOMDocument::class))
			return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

		$document = new DOMDocument('1.0', 'UTF-8');
		$previousErrors = libxml_use_internal_errors(true);
		$document->loadHTML(
			'<!doctype html><html><body><div id="hs-sanitize-root">' . $html . '</div></body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrors);

		$root = $document->getElementById('hs-sanitize-root');
		if (!$root)
			return '';
		self::sanitizeChildren($root);

		$result = '';
		foreach ($root->childNodes as $child)
			$result .= $document->saveHTML($child);
		return $result;
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
			if (in_array($name, array('href', 'src'), true) && !self::safeUrl($attribute->value, $tag === 'img'))
				$element->removeAttribute($attribute->name);
		}
		if ($tag === 'a' && $element->getAttribute('target') === '_blank')
			$element->setAttribute('rel', 'noopener noreferrer');
	}

	private static function safeUrl(string $url, bool $allowDataImage): bool
	{
		$url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#'))
			return true;
		if ($allowDataImage && preg_match('#^data:image/(?:png|gif|jpe?g|webp);base64,#i', $url))
			return true;
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return in_array($scheme, array('http', 'https', 'mailto'), true);
	}
}

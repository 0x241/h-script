<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$controllers = array(
	'module/news/admin/news.php',
	'module/faq/admin/faq.php',
);

foreach ($controllers as $relativePath)
{
	$source = (string) file_get_contents($root . '/' . $relativePath);
	if (!str_contains($source, "View::setPage('el', \$el, 0);"))
		throw new RuntimeException($relativePath . ' must pass editable rich text to Twig without pre-escaping.');
	if (str_contains($source, "View::setPage('el', \$el, 2);"))
		throw new RuntimeException($relativePath . ' still pre-escapes editable rich text.');
}

foreach (array('tpl/admin/header.twig', 'tpl/admin/footer.twig') as $relativePath)
{
	$source = (string) file_get_contents($root . '/' . $relativePath);
	foreach (array("'news/admin/news'", "'faq/admin/faq'") as $module)
		if (!str_contains($source, $module))
			throw new RuntimeException($relativePath . ' does not enable CKEditor for ' . $module . '.');
}

$faqListController = (string) file_get_contents($root . '/module/faq/admin/faqs.php');
$faqListRow = (string) file_get_contents($root . '/tpl/faq/admin/faqs.row.twig');
$faqForm = (string) file_get_contents($root . '/tpl/faq/admin/faq.twig');
$adminFooter = (string) file_get_contents($root . '/tpl/admin/footer.twig');
if (!str_contains($faqListController, "View::setPage('list', \$list, 1);"))
	throw new RuntimeException('The FAQ list must localize values without pre-escaping them.');
if (!str_contains($faqListRow, "l.fAnswer|replace({'</p>': ' '")
	|| !str_contains($faqListRow, '|striptags|trim'))
	throw new RuntimeException('The FAQ list must display a plain-text answer excerpt.');
if (str_contains($faqForm, "1: 'Категория!!'"))
	throw new RuntimeException('The optional FAQ category is still marked as required.');
foreach (array('автоматически создаваемый раздел «Общие вопросы»', 'в режиме «Источник»') as $hint)
	if (!str_contains($faqForm, $hint))
		throw new RuntimeException('The FAQ form is missing the usage hint: ' . $hint);
if (str_contains($adminFooter, 'HtmlEmbed') || str_contains($adminFooter, "'htmlEmbed'"))
	throw new RuntimeException('The misleading raw HTML embed control is still enabled.');

$html = '{!ru!}<h2>Заголовок</h2><p>Абзац <strong>текста</strong>.</p>{!!}';
$twig = new Environment(new ArrayLoader(array(
	'field' => '<textarea data-editor="ckeditor">{{ value }}</textarea>',
)), array('autoescape' => 'html'));
$rendered = $twig->render('field', array('value' => $html));

if (!str_contains($rendered, '&lt;h2&gt;') || str_contains($rendered, '&amp;lt;h2&amp;gt;'))
	throw new RuntimeException('Editable rich text must be escaped exactly once in the textarea response.');

$textareaValue = html_entity_decode(
	(string) preg_replace('/^.*?<textarea[^>]*>|<\/textarea>.*$/s', '', $rendered),
	ENT_QUOTES | ENT_HTML5,
	'UTF-8'
);
if ($textareaValue !== $html)
	throw new RuntimeException('The browser textarea value does not round-trip to the stored rich text.');

echo "Admin rich-text editing tests passed.\n";

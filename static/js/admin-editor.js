import {
	Alignment, Autoformat, BlockQuote, Bold, ClassicEditor, Code, CodeBlock,
	Essentials, FindAndReplace, Font, Fullscreen, GeneralHtmlSupport, Heading,
	HorizontalLine, Image, ImageCaption, ImageInsert, ImageResize,
	ImageStyle, ImageToolbar, ImageUpload, Italic, Link, List, ListProperties,
	MediaEmbed, Paragraph, RemoveFormat, ShowBlocks, SimpleUploadAdapter,
	SourceEditing, SpecialCharacters, SpecialCharactersEssentials, Strikethrough,
	Table, TableCellProperties, TableProperties, TableToolbar, Underline
} from '../lib/ckeditor5/48.2.0/ckeditor5/ckeditor5.js';
const editorLocale = ['ru', 'en', 'de', 'es', 'fr'].includes(document.documentElement.lang)
	? document.documentElement.lang : 'ru';
const editorTranslations = (await import(`../lib/ckeditor5/48.2.0/ckeditor5/translations/${editorLocale}.js`)).default;

for (const field of document.querySelectorAll('textarea[data-editor="ckeditor"]:not([data-editor-ready])')) {
	field.dataset.editorReady = 'true';
	const formCert = field.closest('form')?.querySelector('input[name="__Cert"]')?.value ?? '';
	ClassicEditor.create(field, {
		licenseKey: 'GPL',
		language: editorLocale,
		translations: [editorTranslations],
		plugins: [
			Alignment, Autoformat, BlockQuote, Bold, Code, CodeBlock, Essentials,
			FindAndReplace, Font, Fullscreen, GeneralHtmlSupport, Heading, HorizontalLine,
			Image, ImageCaption, ImageInsert, ImageResize, ImageStyle,
			ImageToolbar, ImageUpload, Italic, Link, List, ListProperties, MediaEmbed,
			Paragraph, RemoveFormat, ShowBlocks, SimpleUploadAdapter, SourceEditing,
			SpecialCharacters, SpecialCharactersEssentials, Strikethrough, Table,
			TableCellProperties, TableProperties, TableToolbar, Underline
		],
		toolbar: {
			items: [
				'undo', 'redo', '|', 'heading', '|', 'fontFamily', 'fontSize',
				'fontColor', 'fontBackgroundColor', '|', 'bold', 'italic', 'underline',
				'strikethrough', 'removeFormat', '|', 'alignment', '|', 'bulletedList',
				'numberedList', '|', 'link', 'insertImage', 'mediaEmbed', 'insertTable',
				'horizontalLine', 'blockQuote', 'codeBlock', '|',
				'findAndReplace', 'showBlocks', 'sourceEditing', 'fullscreen'
			],
			shouldNotGroupWhenFull: false
		},
		menuBar: { isVisible: true },
		image: {
			toolbar: [
				'toggleImageCaption', 'imageTextAlternative', '|', 'imageStyle:inline',
				'imageStyle:wrapText', 'imageStyle:breakText', '|', 'resizeImage'
			]
		},
		table: {
			contentToolbar: [
				'tableColumn', 'tableRow', 'mergeTableCells', 'tableProperties',
				'tableCellProperties'
			]
		},
		simpleUpload: {
			uploadUrl: 'admin/news/upload',
			withCredentials: true,
			headers: { 'X-HS-Form-Cert': formCert }
		},
		mediaEmbed: { previewsInData: true },
		htmlSupport: {
			allow: [{ name: /.*/, attributes: true, classes: true, styles: true }]
		},
		link: {
			addTargetToExternalLinks: true,
			defaultProtocol: 'https://'
		}
	}).then(editor => {
		editor.ui.view.editable.element.classList.add('min-h-80');
	}).catch(error => console.error(error));
}

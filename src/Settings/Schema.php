<?php
/**
 * Declarative settings schema: every field the plugin stores, with its
 * type, default, label, help text and conditional-visibility rules.
 *
 * @package LinkPurpose
 */

namespace LinkPurpose\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the settings form, the sanitizer, the read
 * pipeline and the JS config payload.
 *
 * The Drupal module declares the same ~70 keys three times (form builder,
 * submit handler, config schema). Here one registry drives all of it:
 *
 *   - {@see fields()}   flat `key => definition` map (type, default, UI copy)
 *   - {@see layout()}   the section/group tree the form renders from
 *   - {@see defaults()} `key => default stored value`, derived from fields()
 *
 * Storage keys deliberately keep the Drupal module's camelCase names
 * (`purposeExternalIconType`, …) so the JS payload is a 1:1 pass-through
 * and a future Drupal → WordPress config import needs no remapping.
 *
 * Field definition keys:
 *
 *   type         'textarea' | 'text' | 'checkbox' | 'select'
 *   format       for text/textarea: 'selector' | 'domain' | 'css_class' |
 *                'css_classes' | 'plain' (drives {@see FieldSanitizer})
 *   default      stored default ('' / '1' for checkboxes / option key)
 *   label        translated label
 *   description  translated help text; limited HTML allowed (see
 *                {@see \LinkPurpose\Form\FormRenderer::allowed_html()})
 *   placeholder  input placeholder (usually the effective default)
 *   options      select: `value => label`
 *   depends      list of `array( key, value )` pairs that must ALL match
 *                for the row to be visible (client-side only; the server
 *                never trusts visibility)
 *
 * Labels are built lazily (not in constants) so translations load first.
 */
final class Schema {

	/** Icon-type select vocabulary. */
	const ICON_TYPES = array( 'html', 'classes' );

	/** `insertAdjacentHTML` position vocabulary. */
	const ICON_POSITIONS = array( 'beforeend', 'beforebegin', 'afterbegin', 'afterend' );

	/**
	 * Memoized field map.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $fields = null;

	/**
	 * Memoized layout tree.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private static $layout = null;

	/** Drop the memoized copies (tests switch locale between cases). */
	public static function reset(): void {
		self::$fields = null;
		self::$layout = null;
	}

	/**
	 * Every storage key, in form order.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array_keys( self::fields() );
	}

	/**
	 * `key => default stored value`.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::fields() as $key => $def ) {
			$defaults[ $key ] = (string) $def['default'];
		}
		return $defaults;
	}

	/**
	 * One field definition, or null for an unknown key.
	 *
	 * @param string $key Storage key.
	 * @return array<string,mixed>|null
	 */
	public static function field( string $key ) {
		$fields = self::fields();
		return $fields[ $key ] ?? null;
	}

	/**
	 * Whether a key is one the plugin stores.
	 *
	 * @param string $key Storage key.
	 */
	public static function has( string $key ): bool {
		return array_key_exists( $key, self::fields() );
	}

	/**
	 * Default screen-reader message for a `purpose*Message` key, translated.
	 * Returns '' for keys that are not message fields.
	 *
	 * @param string $key Storage key.
	 */
	public static function message_default( string $key ): string {
		foreach ( self::purposes() as $purpose ) {
			if ( $purpose['message_key'] === $key ) {
				return $purpose['message_default'];
			}
		}
		return '';
	}

	/**
	 * Flat field registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		if ( null !== self::$fields ) {
			return self::$fields;
		}
		$fields = array_merge( self::base_fields(), self::compatibility_fields() );
		foreach ( self::purposes() as $purpose ) {
			$fields = array_merge( $fields, self::purpose_fields( $purpose ) );
		}
		self::$fields = $fields;
		return $fields;
	}

	/**
	 * Section → group → field tree the form renders.
	 *
	 * Mirrors the Drupal form: two fieldsets ("Basic configuration" and
	 * "Icon categories"), each holding collapsible `<details>` groups.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function layout(): array {
		if ( null !== self::$layout ) {
			return self::$layout;
		}
		$purpose_groups = array();
		foreach ( self::purposes() as $id => $purpose ) {
			$purpose_groups[] = array(
				'id'     => $id,
				'title'  => $purpose['title'],
				'intro'  => $purpose['intro'] ?? '',
				'fields' => array_keys( self::purpose_fields( $purpose ) ),
			);
		}

		self::$layout = array(
			array(
				'id'     => 'basic',
				'title'  => __( 'Basic configuration', 'linkpurpose' ),
				'intro'  => '<p>' . esc_html__( 'Most sites only need to define their domain:', 'linkpurpose' ) . '</p>',
				'fields' => array( 'domain' ),
				'groups' => array(
					array(
						'id'     => 'containers',
						'title'  => __( 'Inclusions & exclusions', 'linkpurpose' ),
						'fields' => array( 'roots', 'hideIcon', 'noIconOnImages', 'ignore' ),
					),
					array(
						'id'     => 'compatibility',
						'title'  => __( 'Theme and plugin compatibility', 'linkpurpose' ),
						'fields' => array_keys( self::compatibility_fields() ),
					),
					array(
						'id'     => 'migrating',
						'title'  => __( 'Tips for matching an existing theme', 'linkpurpose' ),
						'markup' => self::migrating_markup(),
					),
				),
			),
			array(
				'id'     => 'icons',
				'title'  => __( 'Icon categories', 'linkpurpose' ),
				'intro'  => self::icons_intro_markup(),
				'fields' => array(),
				'groups' => $purpose_groups,
			),
		);
		return self::$layout;
	}

	/**
	 * Per-purpose metadata. Keys are the library's purpose ids.
	 *
	 * `message_default` is the translated fallback the JS payload uses when
	 * the stored message is empty — the WordPress equivalent of Drupal
	 * running the stored string through `t()`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function purposes(): array {
		return array(
			'external'  => array(
				'prefix'              => 'purposeExternal',
				'title'               => __( 'External link icons', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icons to links to external sites', 'linkpurpose' ),
				'label_prefix'        => __( 'External links', 'linkpurpose' ),
				'message_key'         => 'purposeExternalMessage',
				'message_default'     => __( 'Link is external', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-external',
				'wrapper_placeholder' => 'link-purpose-external-icon',
				'classes_placeholder' => 'fa-solid, fa-up-right-from-square',
				'classes_url'         => 'https://fontawesome.com/icons/up-right-from-square',
				'extra_checkboxes'    => array(
					'purposeExternalNoReferrer' => __( 'External links: add noreferrer attribute', 'linkpurpose' ),
					'purposeExternalNewWindow'  => __( 'External links: open in new window', 'linkpurpose' ),
				),
				'selector'            => array(
					'label'       => __( 'External links: selectors for additional links to consider external', 'linkpurpose' ),
					'placeholder' => __( 'Most sites should not change this.', 'linkpurpose' ),
					'description' => __( 'Used for links that have internal HREF attributes, but should be flagged as external anyway.<br>Provide a complete CSS selector; be as fancy as you need.<br>E.g.: <code><em>.in-the-news a, [href^="https://that-one-subdomain"]</em></code>', 'linkpurpose' ),
				),
			),
			'document'  => array(
				'prefix'              => 'purposeDocument',
				'title'               => __( 'Links to documents', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icon to links to documents', 'linkpurpose' ),
				'label_prefix'        => __( 'Documents', 'linkpurpose' ),
				'message_key'         => 'purposeDocumentMessage',
				'message_default'     => __( 'Link downloads document', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-document',
				'wrapper_placeholder' => 'link-purpose-document-icon',
				'classes_placeholder' => 'fa-regular, fa-file-lines',
				'classes_url'         => 'https://fontawesome.com/icons/file-lines?f=classic&s=regular',
				'selector'            => array(
					'label'       => __( 'Documents: CSS selector', 'linkpurpose' ),
					'placeholder' => self::DOCUMENT_SELECTOR_DEFAULT,
					'description' => sprintf(
						/* translators: %s: the default CSS selector, wrapped in code tags. */
						__( 'Add or remove file types to flag. Separate multiple attributes with commas.<br>Default: %s', 'linkpurpose' ),
						'<br><code><em>' . esc_html( self::DOCUMENT_SELECTOR_DEFAULT ) . '</em></code>'
					),
				),
			),
			'download'  => array(
				'prefix'              => 'purposeDownload',
				'title'               => __( 'Links that download files', 'linkpurpose' ),
				'intro'               => '<p>' . esc_html__( 'Note that this category "loses" to documents: it is for non-document file downloads with a "download" attribute on the link.', 'linkpurpose' ) . '</p>',
				'toggle_label'        => __( 'Add icon to links that download files', 'linkpurpose' ),
				'label_prefix'        => __( 'Download links', 'linkpurpose' ),
				'message_key'         => 'purposeDownloadMessage',
				'message_default'     => __( 'Link downloads file', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-download',
				'wrapper_placeholder' => 'link-purpose-download-icon',
				'classes_placeholder' => 'fa-solid, fa-download',
				'classes_url'         => 'https://fontawesome.com/icons/download',
				'selector'            => array(
					'label'       => __( 'Download links: selectors for additional links to consider downloads', 'linkpurpose' ),
					'placeholder' => __( 'Most sites should not change this.', 'linkpurpose' ),
					'description' => __( 'Set if you want to flag some links <em>without</em> a download attribute.<br>E.g.: <code><em>[download], [href$=".zip"]</em></code>', 'linkpurpose' ),
				),
			),
			'app'       => array(
				'prefix'              => 'purposeApp',
				'title'               => __( 'Links that open applications', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icon to links that open applications', 'linkpurpose' ),
				'label_prefix'        => __( 'App links', 'linkpurpose' ),
				'message_key'         => 'purposeAppMessage',
				'message_default'     => __( 'Link opens app', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-app',
				'wrapper_placeholder' => 'link-purpose-app-icon',
				'classes_placeholder' => 'fa-solid, fa-window-restore',
				'classes_url'         => 'https://fontawesome.com/icons/window-restore',
				'selector'            => array(
					'label'       => __( 'App links: selector', 'linkpurpose' ),
					'placeholder' => __( 'Most sites should not change this.', 'linkpurpose' ),
					'description' => __( 'Modify to map specific protocols out of the not().<br>Default: <code><em>:is([href*="://"]):not([href^="https:"], [href^="http:"], [href^="file:"])</em></code>', 'linkpurpose' ),
				),
			),
			'mail'      => array(
				'prefix'              => 'purposeMail',
				'title'               => __( 'Links to email addresses', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icon to links to email addresses', 'linkpurpose' ),
				'label_prefix'        => __( 'Email links', 'linkpurpose' ),
				'message_key'         => 'purposeMailMessage',
				'message_default'     => __( 'Link sends email', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-mailto',
				'wrapper_placeholder' => 'link-purpose-mail-icon',
				'classes_placeholder' => 'fa-regular, fa-envelope',
				'classes_url'         => 'https://fontawesome.com/icons/envelope?f=classic&s=regular',
			),
			'tel'       => array(
				'prefix'              => 'purposeTel',
				'title'               => __( 'Telephone links', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icon to telephone links', 'linkpurpose' ),
				'label_prefix'        => __( 'Phone links', 'linkpurpose' ),
				'message_key'         => 'purposeTelMessage',
				'message_default'     => __( 'Link opens phone app', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-tel',
				'wrapper_placeholder' => 'link-purpose-tel-icon',
				'classes_placeholder' => 'fa-solid, fa-square-phone',
				'classes_url'         => 'https://fontawesome.com/icons/square-phone',
			),
			'newWindow' => array(
				'prefix'              => 'purposeNewWindow',
				'title'               => __( 'Links that open in a new window', 'linkpurpose' ),
				'toggle_label'        => __( 'Add icon to links that open a new window', 'linkpurpose' ),
				'label_prefix'        => __( 'New window links', 'linkpurpose' ),
				'message_key'         => 'purposeNewWindowMessage',
				'message_default'     => __( 'Link opens in new window', 'linkpurpose' ),
				'class_placeholder'   => 'link-purpose-window',
				'wrapper_placeholder' => 'link-purpose-window-icon',
				'classes_placeholder' => 'fa-regular, fa-window-restore',
				'classes_url'         => 'https://fontawesome.com/icons/window-restore?f=classic&s=regular',
			),
		);
	}

	/** Library default for the document selector (also the form placeholder). */
	const DOCUMENT_SELECTOR_DEFAULT = "[href^='/document/'], [href$='.pdf'], [href*='.pdf?'], [href$='.doc'], [href$='.docx'], [href*='.doc?'], [href*='.docx?'], [href$='.ppt'], [href$='.pptx'], [href*='.ppt?'], [href*='.pptx?'], [href^='https://docs.google']";

	/* ===== Field groups ===== */

	/**
	 * "Basic configuration" top-level + "Inclusions & exclusions" fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function base_fields(): array {
		return array(
			'domain'         => array(
				'type'        => 'textarea',
				'format'      => 'domain',
				'default'     => '',
				'label'       => __( 'Additional domains to consider internal in absolute URLs', 'linkpurpose' ),
				'placeholder' => '', // Filled at render time with home_url(); see FormRenderer.
				'description' => __( 'The current site\'s domain is always considered internal.<br>Provide a comma separated list of domains, optionally including protocols, trailing paths, subdomains or * as a leading subdomain wildcard:<br>e.g.: <code><em>www.only-www.net, *.all-subdomains.net, a-shared-site.net/just-my-blog</em></code>', 'linkpurpose' ),
			),
			'roots'          => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Page areas to check', 'linkpurpose' ),
				'placeholder' => 'body',
				'description' => sprintf(
					/* translators: %s: link to the MDN CSS selectors reference, reading "CSS selector". */
					__( '%s, e.g.: <code><em>main, #footer</em></code>', 'linkpurpose' ),
					'<a target="_blank" rel="noopener" href="https://developer.mozilla.org/en-US/docs/Learn/CSS/Building_blocks/Selectors">' . esc_html__( 'CSS selector', 'linkpurpose' ) . '</a>'
				),
			),
			'hideIcon'       => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Visually hide the icon on these links', 'linkpurpose' ),
				'description' => __( 'CSS selector, e.g. <code><em>.card a</em></code><br>When context makes the link\'s purpose visually obvious, the icon can be visibly hidden using this setting or your CSS: <code><em>.card .link-purpose-icon { display: none; }</em></code>', 'linkpurpose' ),
			),
			'noIconOnImages' => array(
				'type'        => 'checkbox',
				'default'     => '',
				'label'       => __( 'Visually hide icons on links with images by default', 'linkpurpose' ),
				'description' => __( 'When enabled, you will need to write CSS to <strong>reveal</strong> the icon span (<code>.link-purpose-icon</code>) on links containing <code>img, svg</code> or <code>figure</code> tags.<br>This is not recommended on new sites: noticing and hiding redundant icons is much less error-prone than noticing and revealing missing icons.', 'linkpurpose' ),
			),
			'ignore'         => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Ignore these links entirely', 'linkpurpose' ),
				'description' => __( 'Only use when machine-readable context or the text of the link itself makes the text of the screen reader hint redundant.<br>Links in the admin bar (<code><em>#wpadminbar a</em></code>) are always ignored.<br>E.g.: <code><em>#external-links-list a, .in-the-news a</em></code>.', 'linkpurpose' ),
			),
		);
	}

	/**
	 * "Theme and plugin compatibility" fields.
	 *
	 * Drupal's `noAggregate` checkbox has no WordPress counterpart (core
	 * does not aggregate scripts) and is intentionally absent.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function compatibility_fields(): array {
		return array(
			'suppressNoBreak'             => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Do not prevent line breaks between last word and icon for these links', 'linkpurpose' ),
				'description' => __( 'Only recommended if issues with gaps or overflow cannot be fixed in the theme; this makes it likely the icon falls to the next line. Set to "a" to disable the behavior altogether; otherwise provide exact matches: <code><em>#child-select a, .direct-select</em></code>.', 'linkpurpose' ),
			),
			'insertIconOutsideHiddenSpan' => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Insert icon outside of these hidden spans', 'linkpurpose' ),
				'placeholder' => '.screen-reader-text, .sr-only, .visually-hidden',
				'description' => __( 'Prevents icons from being visually hidden if links already contain screen-reader-only text.<br>Default: <code><em>.screen-reader-text, .sr-only, .visually-hidden</em></code>', 'linkpurpose' ),
			),
			'shadowComponents'            => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Mark links inside this shadow DOM', 'linkpurpose' ),
				'description' => __( '<em>Most sites do not have Web components and can ignore this setting.</em><br>E.g.: <code><em>custom-tag-tab-widget, .shadow-embed</em></code>', 'linkpurpose' ),
			),
			'noRunIfPresent'              => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Do not mark any links if elements are detected', 'linkpurpose' ),
				'description' => __( 'Disables the plugin on the page, e.g. while a front-end page builder is active.<br>Default: <code><em>.elementor-editor-active, .wp-customizer-unloading</em></code>', 'linkpurpose' ),
			),
			'noRunIfAbsent'               => array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => __( 'Do not mark any links if elements are absent', 'linkpurpose' ),
				'description' => '',
			),
			'themePreprocess'             => array(
				'type'        => 'checkbox',
				'default'     => '',
				'label'       => __( 'Attach library then stop; theme will mark links', 'linkpurpose' ),
				'description' => __( 'Set if you want to preprocess the options object in theme or plugin JS, e.g. to add additional link categories or use custom SVG icons.<br>Your theme will need its own copy of <em>linkpurpose-wp.js</em> that reads <em>window.linkpurposeSettings</em> and initializes the library. Most sites can instead define a <em>window.linkPurposeOptions</em> callback, which receives the built options object before the library starts.', 'linkpurpose' ),
			),
		);
	}

	/**
	 * The eight-ish fields each purpose group carries, in Drupal form order.
	 *
	 * @param array<string,mixed> $purpose One entry from {@see purposes()}.
	 * @return array<string,array<string,mixed>>
	 */
	private static function purpose_fields( array $purpose ): array {
		$prefix  = $purpose['prefix'];
		$label   = $purpose['label_prefix'];
		$enabled = array( array( $prefix, '1' ) );

		$fields = array(
			$prefix => array(
				'type'    => 'checkbox',
				'default' => '1',
				'label'   => $purpose['toggle_label'],
			),
		);

		foreach ( $purpose['extra_checkboxes'] ?? array() as $key => $checkbox_label ) {
			$fields[ $key ] = array(
				'type'    => 'checkbox',
				'default' => '',
				'label'   => $checkbox_label,
				'depends' => $enabled,
			);
		}

		$fields[ $prefix . 'IconType' ] = array(
			'type'    => 'select',
			'default' => 'html',
			/* translators: %s: link category, e.g. "External links". */
			'label'   => sprintf( __( '%s: icon type', 'linkpurpose' ), $label ),
			'options' => array(
				'html'    => __( 'SVG (default)', 'linkpurpose' ),
				'classes' => __( 'Classes (e.g., FontAwesome)', 'linkpurpose' ),
			),
			'depends' => $enabled,
		);

		$fields[ $prefix . 'IconClasses' ] = array(
			'type'        => 'textarea',
			'format'      => 'css_classes',
			'default'     => '',
			/* translators: %s: link category, e.g. "External links". */
			'label'       => sprintf( __( '%s: icon classes', 'linkpurpose' ), $label ),
			'placeholder' => $purpose['classes_placeholder'],
			'description' => sprintf(
				/* translators: %s: link to the default FontAwesome icon, reading as the default class list. */
				__( 'Separate multiple classes with commas. Default: %s', 'linkpurpose' ),
				'<a target="_blank" rel="noopener" href="' . esc_url( $purpose['classes_url'] ) . '">' . esc_html( $purpose['classes_placeholder'] ) . '</a>'
			),
			'depends'     => array_merge( $enabled, array( array( $prefix . 'IconType', 'classes' ) ) ),
		);

		$fields[ $prefix . 'IconPosition' ] = array(
			'type'    => 'select',
			'default' => 'beforeend',
			/* translators: %s: link category, e.g. "External links". */
			'label'   => sprintf( __( '%s: icon position', 'linkpurpose' ), $label ),
			'options' => array(
				'beforeend'   => __( 'Append (default)', 'linkpurpose' ),
				'beforebegin' => __( 'Before', 'linkpurpose' ),
				'afterbegin'  => __( 'Prepend', 'linkpurpose' ),
				'afterend'    => __( 'After', 'linkpurpose' ),
			),
			'depends' => $enabled,
		);

		$fields[ $purpose['message_key'] ] = array(
			'type'        => 'text',
			'format'      => 'plain',
			'default'     => '',
			/* translators: %s: link category, e.g. "External links". */
			'label'       => sprintf( __( '%s: hidden text for screen readers', 'linkpurpose' ), $label ),
			'placeholder' => $purpose['message_default'],
			'depends'     => $enabled,
		);

		$fields[ $prefix . 'Class' ] = array(
			'type'        => 'text',
			'format'      => 'css_class',
			'default'     => '',
			/* translators: %s: link category, e.g. "External links". */
			'label'       => sprintf( __( '%s: class added to the &lt;a&gt; tag', 'linkpurpose' ), $label ),
			'placeholder' => $purpose['class_placeholder'],
			'depends'     => $enabled,
		);

		$fields[ $prefix . 'IconWrapperClass' ] = array(
			'type'        => 'text',
			'format'      => 'css_class',
			'default'     => '',
			/* translators: %s: link category, e.g. "External links". */
			'label'       => sprintf( __( '%s: class added to icon wrapper &lt;span&gt;', 'linkpurpose' ), $label ),
			'placeholder' => $purpose['wrapper_placeholder'],
			'depends'     => $enabled,
		);

		if ( isset( $purpose['selector'] ) ) {
			$fields[ $prefix . 'Selector' ] = array(
				'type'        => 'textarea',
				'format'      => 'selector',
				'default'     => '',
				'label'       => $purpose['selector']['label'],
				'placeholder' => $purpose['selector']['placeholder'],
				'description' => $purpose['selector']['description'],
				'depends'     => $enabled,
			);
		}

		return $fields;
	}

	/* ===== Static markup blocks ===== */

	/**
	 * Intro paragraphs for the "Icon categories" fieldset.
	 */
	private static function icons_intro_markup(): string {
		return '<p>' . esc_html__( 'Links receive the most specific icon: a new-window link to a document will be marked as a document.', 'linkpurpose' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: link to the JS library documentation, reading "Library docs". */
				esc_html__( 'See the %s for output examples.', 'linkpurpose' ),
				'<a href="https://itmaybejj.github.io/linkpurpose/" target="_blank" rel="noopener">' . esc_html__( 'Library docs', 'linkpurpose' ) . '</a>'
			) . '</p>';
	}

	/**
	 * Collapsible tips block. The Drupal module's version targets the
	 * External Link module's markup; the WordPress copy is generic.
	 */
	private static function migrating_markup(): string {
		return '<p>' . esc_html__( 'If your theme already styles external-link icons from another plugin and you need near-identical markup:', 'linkpurpose' ) . '</p>'
			. '<ol>'
			. '<li>' . esc_html__( 'Switch the relevant categories below to the "Classes" icon type and, if the old plugin used an icon font, add that font\'s classes to "Icon classes".', 'linkpurpose' ) . '</li>'
			. '<li>' . esc_html__( 'Set "Class added to the <a> tag" and "Class added to icon wrapper <span>" to the class names your theme CSS targets.', 'linkpurpose' ) . '</li>'
			. '<li>' . esc_html__( 'If things still do not look right, search your theme for the old selectors and point them at the new classes instead.', 'linkpurpose' ) . '</li>'
			. '</ol>';
	}
}

<?php
/**
 * Preparation for the final page rendering.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Output;

use Article;
use Content;
use CSSJanus;
use Exception;
use ExtensionRegistry;
use File;
use HtmlArmor;
use InvalidArgumentException;
use JavaScriptContent;
use Language;
use LanguageCode;
use MediaWiki\Cache\LinkCache;
use MediaWiki\Config\Config;
use MediaWiki\Context\ContextSource;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\HookContainer\ProtectedHookAccessorTrait;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageReference;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputFlags;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Request\ContentSecurityPolicy;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\ResourceLoader as RL;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Session\SessionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use MediaWiki\Utils\MWTimestamp;
use MWDebug;
use OOUI\Element;
use OOUI\Theme;
use ParserOptions;
use RuntimeException;
use Skin;
use TextContent;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Bcp47Code\Bcp47Code;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\Parsoid\Core\TOCData;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\RelPath;
use Wikimedia\WrappedString;
use Wikimedia\WrappedStringList;

/**
 * This is one of the Core classes and should
 * be read at least once by any new developers. Also documented at
 * https://www.mediawiki.org/wiki/Manual:Architectural_modules/OutputPage
 *
 * This class is used to prepare the final rendering. A skin is then
 * applied to the output parameters (links, javascript, html, categories ...).
 *
 * @todo FIXME: Another class handles sending the whole page to the client.
 *
 * Some comments comes from a pairing session between Zak Greant and Antoine Musso
 * in November 2010.
 *
 * @todo document
 */
class OutputPage extends ContextSource {
	use ProtectedHookAccessorTrait;

	/** Output CSP policies as headers */
	public const CSP_HEADERS = 'headers';
	/** Output CSP policies as meta tags */
	public const CSP_META = 'meta';

	// Constants for getJSVars()
	private const JS_VAR_EARLY = 1;
	private const JS_VAR_LATE = 2;

	// Core config vars that opt-in to JS_VAR_LATE.
	// Extensions use the 'LateJSConfigVarNames' attribute instead.
	private const CORE_LATE_JS_CONFIG_VAR_NAMES = [];

	/** @var bool Whether setupOOUI() has been called */
	private static $oouiSetupDone = false;

	/** @var string[][] Should be private. Used with addMeta() which adds "<meta>" */
	protected $mMetatags = [];

	/** @var array */
	protected $mLinktags = [];

	/** @var string|false */
	protected $mCanonicalUrl = false;

	/**
	 * @var string The contents of <h1>
	 */
	private $mPageTitle = '';

	/**
	 * @var string The displayed title of the page. Different from page title
	 * if overridden by display title magic word or hooks. Can contain safe
	 * HTML. Different from page title which may contain messages such as
	 * "Editing X" which is displayed in h1. This can be used for other places
	 * where the page name is referred on the page.
	 */
	private $displayTitle;

	/** @var bool See OutputPage::couldBePublicCached. */
	private $cacheIsFinal = false;

	/**
	 * @var string Contains all of the "<body>" content. Should be private we
	 *   got set/get accessors and the append() method.
	 */
	public $mBodytext = '';

	/** @var string Stores contents of "<title>" tag */
	private $mHTMLtitle = '';

	/**
	 * @var bool Is the displayed content related to the source of the
	 *   corresponding wiki article.
	 */
	private $mIsArticle = false;

	/** @var bool Stores "article flag" toggle. */
	private $mIsArticleRelated = true;

	/** @var bool Is the content subject to copyright */
	private $mHasCopyright = false;

	/**
	 * @var bool We have to set isPrintable(). Some pages should
	 * never be printed (ex: redirections).
	 */
	private $mPrintable = false;

	/**
	 * @var ?TOCData Table of Contents information from ParserOutput, or
	 *   null if no TOCData was ever set.
	 */
	private $tocData;

	/**
	 * @var array Contains the page subtitle. Special pages usually have some
	 *   links here. Don't confuse with site subtitle added by skins.
	 */
	private $mSubtitle = [];

	/** @var string */
	public $mRedirect = '';

	/** @var int */
	protected $mStatusCode;

	/**
	 * @var string Used for sending cache control.
	 *   The whole caching system should probably be moved into its own class.
	 */
	protected $mLastModified = '';

	/**
	 * @var string[][]
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mCategoryLinks = [];

	/**
	 * @var string[][]
	 * @deprecated since 1.38, will be made private (T301020)
	 */
	protected $mCategories = [
		'hidden' => [],
		'normal' => [],
	];

	/**
	 * @var string[]
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mIndicators = [];

	/**
	 * @var string[] Array of Interwiki Prefixed (non DB key) Titles (e.g. 'fr:Test page')
	 */
	private $mLanguageLinks = [];

	/**
	 * Used for JavaScript (predates ResourceLoader)
	 * @todo We should split JS / CSS.
	 * mScripts content is inserted as is in "<head>" by Skin. This might
	 * contain either a link to a stylesheet or inline CSS.
	 */
	private $mScripts = '';

	/** @var string Inline CSS styles. Use addInlineStyle() sparingly */
	protected $mInlineStyles = '';

	/**
	 * Additional <html> classes; This should be rarely modified; prefer mAdditionalBodyClasses.
	 * @var array
	 */
	protected $mAdditionalHtmlClasses = [];

	/**
	 * @var string[] Array of elements in "<head>". Parser might add its own headers!
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mHeadItems = [];

	/** @var array Additional <body> classes; there are also <body> classes from other sources */
	protected $mAdditionalBodyClasses = [];

	/**
	 * @var array
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mModules = [];

	/**
	 * @var array
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mModuleStyles = [];

	/** @var ResourceLoader */
	protected $mResourceLoader;

	/** @var RL\ClientHtml */
	private $rlClient;

	/** @var RL\Context */
	private $rlClientContext;

	/** @var array */
	private $rlExemptStyleModules;

	/**
	 * @var array
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mJsConfigVars = [];

	/**
	 * @var array<int,array<string,int>>
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mTemplateIds = [];

	/** @var array */
	protected $mImageTimeKeys = [];

	/** @var string */
	public $mRedirectCode = '';

	protected $mFeedLinksAppendQuery = null;

	/** @var array
	 * What level of 'untrustworthiness' is allowed in CSS/JS modules loaded on this page?
	 * @see RL\Module::$origin
	 * RL\Module::ORIGIN_ALL is assumed unless overridden;
	 */
	protected $mAllowedModules = [
		RL\Module::TYPE_COMBINED => RL\Module::ORIGIN_ALL,
	];

	/** @var bool Whether output is disabled.  If this is true, the 'output' method will do nothing. */
	protected $mDoNothing = false;

	// Parser related.

	/**
	 * lazy initialised, use parserOptions()
	 * @var ParserOptions
	 */
	protected $mParserOptions = null;

	/**
	 * Handles the Atom / RSS links.
	 * We probably only support Atom in 2011.
	 * @see $wgAdvertisedFeedTypes
	 */
	private $mFeedLinks = [];

	/**
	 * @var bool Set to false to send no-cache headers, disabling
	 * client-side caching. (This variable should really be named
	 * in the opposite sense; see ::disableClientCache().)
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mEnableClientCache = true;

	/** @var bool Flag if output should only contain the body of the article. */
	private $mArticleBodyOnly = false;

	/**
	 * @var bool
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mNewSectionLink = false;

	/**
	 * @var bool
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	protected $mHideNewSectionLink = false;

	/**
	 * @var bool Comes from the parser. This was probably made to load CSS/JS
	 * only if we had "<gallery>". Used directly in CategoryPage.php.
	 * Looks like ResourceLoader can replace this.
	 * @deprecated since 1.38; will be made private (T301020)
	 */
	public $mNoGallery = false;

	/** @var int Cache stuff. Looks like mEnableClientCache */
	protected $mCdnMaxage = 0;
	/** @var int Upper limit on mCdnMaxage */
	protected $mCdnMaxageLimit = INF;

	/**
	 * @var bool Controls if anti-clickjacking / frame-breaking headers will
	 * be sent. This should be done for pages where edit actions are possible.
	 * Setter: $this->setPreventClickjacking()
	 */
	protected $mPreventClickjacking = true;

	/** @var int|null To include the variable {{REVISIONID}} */
	private $mRevisionId = null;

	/** @var bool|null */
	private $mRevisionIsCurrent = null;

	/** @var string */
	private $mRevisionTimestamp = null;

	/** @var array */
	protected $mFileVersion = null;

	/**
	 * @var array An array of stylesheet filenames (relative from skins path),
	 * with options for CSS media, IE conditions, and RTL/LTR direction.
	 * For internal use; add settings in the skin via $this->addStyle()
	 *
	 * Style again! This seems like a code duplication since we already have
	 * mStyles. This is what makes Open Source amazing.
	 */
	protected $styles = [];

	private $mIndexPolicy = 'index';
	private $mFollowPolicy = 'follow';

	/** @var array */
	private $mRobotsOptions = [ 'max-image-preview' => 'standard' ];

	/**
	 * @var array Headers that cause the cache to vary.  Key is header name,
	 * value should always be null.  (Value was an array of options for
	 * the `Key` header, which was deprecated in 1.32 and removed in 1.34.)
	 */
	private $mVaryHeader = [
		'Accept-Encoding' => null,
	];

	/**
	 * If the current page was reached through a redirect, $mRedirectedFrom contains the title
	 * of the redirect.
	 *
	 * @var PageReference
	 */
	private $mRedirectedFrom = null;

	/**
	 * Additional key => value data
	 */
	private $mProperties = [];

	/**
	 * @var string|null ResourceLoader target for load.php links. If null, will be omitted
	 */
	private $mTarget = null;

	/**
	 * @var bool Whether parser output contains a table of contents
	 */
	private $mEnableTOC = false;

	/**
	 * @var array<string,bool> Flags set in the ParserOutput
	 */
	private $mOutputFlags = [];

	/**
	 * @var string|null The URL to send in a <link> element with rel=license
	 */
	private $copyrightUrl;

	/**
	 * @var Language|null
	 */
	private $contentLang;

	/** @var array Profiling data */
	private $limitReportJSData = [];

	/** @var array Map Title to Content */
	private $contentOverrides = [];

	/** @var callable[] */
	private $contentOverrideCallbacks = [];

	/**
	 * Link: header contents
	 */
	private $mLinkHeader = [];

	/**
	 * @var ContentSecurityPolicy
	 */
	private $CSP;

	private string $cspOutputMode = self::CSP_HEADERS;

	/**
	 * @var array A cache of the names of the cookies that will influence the cache
	 */
	private static $cacheVaryCookies = null;

	/**
	 * Constructor for OutputPage. This should not be called directly.
	 * Instead a new RequestContext should be created and it will implicitly create
	 * a OutputPage tied to that context.
	 * @param IContextSource $context
	 */
	public function __construct( IContextSource $context ) {
		$this->setContext( $context );
		$this->CSP = new ContentSecurityPolicy(
			$context->getRequest()->response(),
			$context->getConfig(),
			$this->getHookContainer()
		);
	}

	/**
	 * Redirect to $url rather than displaying the normal page
	 *
	 * @param string $url
	 * @param string|int $responsecode HTTP status code
	 */
	public function redirect( $url, $responsecode = '302' ) {
		# Strip newlines as a paranoia check for header injection in PHP<5.1.2
		$this->mRedirect = str_replace( "\n", '', $url );
		$this->mRedirectCode = (string)$responsecode;
	}

	/**
	 * Get the URL to redirect to, or an empty string if not redirect URL set
	 *
	 * @return string
	 */
	public function getRedirect() {
		return $this->mRedirect;
	}

	/**
	 * Set the copyright URL to send with the output.
	 * Empty string to omit, null to reset.
	 *
	 * @since 1.26
	 *
	 * @param string|null $url
	 */
	public function setCopyrightUrl( $url ) {
		$this->copyrightUrl = $url;
	}

	/**
	 * Set the HTTP status code to send with the output.
	 *
	 * @param int $statusCode
	 */
	public function setStatusCode( $statusCode ) {
		$this->mStatusCode = $statusCode;
	}

	/**
	 * Add a new "<meta>" tag
	 * To add an http-equiv meta tag, precede the name with "http:"
	 *
	 * @param string $name Name of the meta tag
	 * @param string $val Value of the meta tag
	 */
	public function addMeta( $name, $val ) {
		$this->mMetatags[] = [ $name, $val ];
	}

	/**
	 * Returns the current <meta> tags
	 *
	 * @since 1.25
	 * @return array
	 */
	public function getMetaTags() {
		return $this->mMetatags;
	}

	/**
	 * Add a new \<link\> tag to the page header.
	 *
	 * Note: use setCanonicalUrl() for rel=canonical.
	 *
	 * @param array $linkarr Associative array of attributes.
	 */
	public function addLink( array $linkarr ) {
		$this->mLinktags[] = $linkarr;
	}

	/**
	 * Returns the current <link> tags
	 *
	 * @since 1.25
	 * @return array
	 */
	public function getLinkTags() {
		return $this->mLinktags;
	}

	/**
	 * Set the URL to be used for the <link rel=canonical>. This should be used
	 * in preference to addLink(), to avoid duplicate link tags.
	 * @param string $url
	 */
	public function setCanonicalUrl( $url ) {
		$this->mCanonicalUrl = $url;
	}

	/**
	 * Returns the URL to be used for the <link rel=canonical> if
	 * one is set.
	 *
	 * @since 1.25
	 * @return bool|string
	 */
	public function getCanonicalUrl() {
		return $this->mCanonicalUrl;
	}

	/**
	 * Add raw HTML to the list of scripts (including \<script\> tag, etc.)
	 * Internal use only. Use OutputPage::addModules() or OutputPage::addJsConfigVars()
	 * if possible.
	 *
	 * @param string $script Raw HTML
	 * @param-taint $script exec_html
	 */
	public function addScript( $script ) {
		$this->mScripts .= $script;
	}

	/**
	 * Add a JavaScript file to be loaded as `<script>` on this page.
	 *
	 * Internal use only. Use OutputPage::addModules() if possible.
	 *
	 * @param string $file URL to file (absolute path, protocol-relative, or full url)
	 * @param string|null $unused Previously used to change the cache-busting query parameter
	 */
	public function addScriptFile( $file, $unused = null ) {
		$this->addScript( Html::linkedScript( $file ) );
	}

	/**
	 * Add a self-contained script tag with the given contents
	 * Internal use only. Use OutputPage::addModules() if possible.
	 *
	 * @param string $script JavaScript text, no script tags
	 * @param-taint $script exec_html
	 */
	public function addInlineScript( $script ) {
		$this->mScripts .= Html::inlineScript( "\n$script\n" ) . "\n";
	}

	/**
	 * Filter an array of modules to remove insufficiently trustworthy members, and modules
	 * which are no longer registered (eg a page is cached before an extension is disabled)
	 * @param string[] $modules
	 * @param string|null $position Unused
	 * @param string $type
	 * @return string[]
	 */
	protected function filterModules( array $modules, $position = null,
		$type = RL\Module::TYPE_COMBINED
	) {
		$resourceLoader = $this->getResourceLoader();
		$filteredModules = [];
		foreach ( $modules as $val ) {
			$module = $resourceLoader->getModule( $val );
			if ( $module instanceof RL\Module
				&& $module->getOrigin() <= $this->getAllowedModules( $type )
			) {
				$filteredModules[] = $val;
			}
		}
		return $filteredModules;
	}

	/**
	 * Get the list of modules to include on this page
	 *
	 * @param bool $filter Whether to filter out insufficiently trustworthy modules
	 * @param string|null $position Unused
	 * @param string $param
	 * @param string $type
	 * @return string[] Array of module names
	 */
	public function getModules( $filter = false, $position = null, $param = 'mModules',
		$type = RL\Module::TYPE_COMBINED
	) {
		$modules = array_values( array_unique( $this->$param ) );
		return $filter
			? $this->filterModules( $modules, null, $type )
			: $modules;
	}

	/**
	 * Load one or more ResourceLoader modules on this page.
	 *
	 * @param string|array $modules Module name (string) or array of module names
	 */
	public function addModules( $modules ) {
		$this->mModules = array_merge( $this->mModules, (array)$modules );
	}

	/**
	 * Get the list of style-only modules to load on this page.
	 *
	 * @param bool $filter
	 * @param string|null $position Unused
	 * @return string[] Array of module names
	 */
	public function getModuleStyles( $filter = false, $position = null ) {
		return $this->getModules( $filter, null, 'mModuleStyles',
			RL\Module::TYPE_STYLES
		);
	}

	/**
	 * Load the styles of one or more style-only ResourceLoader modules on this page.
	 *
	 * Module styles added through this function will be loaded as a stylesheet,
	 * using a standard `<link rel=stylesheet>` HTML tag, rather than as a combined
	 * Javascript and CSS package. Thus, they will even load when JavaScript is disabled.
	 *
	 * @param string|array $modules Module name (string) or array of module names
	 */
	public function addModuleStyles( $modules ) {
		$this->mModuleStyles = array_merge( $this->mModuleStyles, (array)$modules );
	}

	/**
	 * @return null|string ResourceLoader target
	 */
	public function getTarget() {
		return $this->mTarget;
	}

	/**
	 * Force the given Content object for the given page, for things like page preview.
	 * @see self::addContentOverrideCallback()
	 * @since 1.32
	 * @param LinkTarget|PageReference $target
	 * @param Content $content
	 */
	public function addContentOverride( $target, Content $content ) {
		if ( !$this->contentOverrides ) {
			// Register a callback for $this->contentOverrides on the first call
			$this->addContentOverrideCallback( function ( $target ) {
				$key = $target->getNamespace() . ':' . $target->getDBkey();
				return $this->contentOverrides[$key] ?? null;
			} );
		}

		$key = $target->getNamespace() . ':' . $target->getDBkey();
		$this->contentOverrides[$key] = $content;
	}

	/**
	 * Add a callback for mapping from a Title to a Content object, for things
	 * like page preview.
	 * @see RL\Context::getContentOverrideCallback()
	 * @since 1.32
	 * @param callable $callback
	 */
	public function addContentOverrideCallback( callable $callback ) {
		$this->contentOverrideCallbacks[] = $callback;
	}

	/**
	 * Add a class to the <html> element. This should rarely be used.
	 * Instead use OutputPage::addBodyClasses() if possible.
	 *
	 * @unstable Experimental since 1.35. Prefer OutputPage::addBodyClasses()
	 * @param string|string[] $classes One or more classes to add
	 */
	public function addHtmlClasses( $classes ) {
		$this->mAdditionalHtmlClasses = array_merge( $this->mAdditionalHtmlClasses, (array)$classes );
	}

	/**
	 * Get an array of head items
	 *
	 * @return string[]
	 */
	public function getHeadItemsArray() {
		return $this->mHeadItems;
	}

	/**
	 * Add or replace a head item to the output
	 *
	 * Whenever possible, use more specific options like ResourceLoader modules,
	 * OutputPage::addLink(), OutputPage::addMeta() and OutputPage::addFeedLink()
	 * Fallback options for those are: OutputPage::addStyle, OutputPage::addScript(),
	 * OutputPage::addInlineScript() and OutputPage::addInlineStyle()
	 * This would be your very LAST fallback.
	 *
	 * @param string $name Item name
	 * @param string $value Raw HTML
	 * @param-taint $value exec_html
	 */
	public function addHeadItem( $name, $value ) {
		$this->mHeadItems[$name] = $value;
	}

	/**
	 * Add one or more head items to the output
	 *
	 * @since 1.28
	 * @param string|string[] $values Raw HTML
	 * @param-taint $values exec_html
	 */
	public function addHeadItems( $values ) {
		$this->mHeadItems = array_merge( $this->mHeadItems, (array)$values );
	}

	/**
	 * Check if the header item $name is already set
	 *
	 * @param string $name Item name
	 * @return bool
	 */
	public function hasHeadItem( $name ) {
		return isset( $this->mHeadItems[$name] );
	}

	/**
	 * Add a class to the <body> element
	 *
	 * @since 1.30
	 * @param string|string[] $classes One or more classes to add
	 */
	public function addBodyClasses( $classes ) {
		$this->mAdditionalBodyClasses = array_merge( $this->mAdditionalBodyClasses, (array)$classes );
	}

	/**
	 * Set whether the output should only contain the body of the article,
	 * without any skin, sidebar, etc.
	 * Used e.g. when calling with "action=render".
	 *
	 * @param bool $only Whether to output only the body of the article
	 */
	public function setArticleBodyOnly( $only ) {
		$this->mArticleBodyOnly = $only;
	}

	/**
	 * Return whether the output will contain only the body of the article
	 *
	 * @return bool
	 */
	public function getArticleBodyOnly() {
		return $this->mArticleBodyOnly;
	}

	/**
	 * Set an additional output property
	 * @since 1.21
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function setProperty( $name, $value ) {
		$this->mProperties[$name] = $value;
	}

	/**
	 * Get an additional output property
	 * @since 1.21
	 *
	 * @param string $name
	 * @return mixed Property value or null if not found
	 */
	public function getProperty( $name ) {
		return $this->mProperties[$name] ?? null;
	}

	/**
	 * checkLastModified tells the client to use the client-cached page if
	 * possible. If successful, the OutputPage is disabled so that
	 * any future call to OutputPage->output() have no effect.
	 *
	 * Side effect: sets mLastModified for Last-Modified header
	 *
	 * @param string $timestamp
	 *
	 * @return bool True if cache-ok headers was sent.
	 */
	public function checkLastModified( $timestamp ) {
		if ( !$timestamp || $timestamp == '19700101000000' ) {
			wfDebug( __METHOD__ . ": CACHE DISABLED, NO TIMESTAMP" );
			return false;
		}
		$config = $this->getConfig();
		if ( !$config->get( MainConfigNames::CachePages ) ) {
			wfDebug( __METHOD__ . ": CACHE DISABLED" );
			return false;
		}

		$timestamp = wfTimestamp( TS_MW, $timestamp );
		$modifiedTimes = [
			'page' => $timestamp,
			'user' => $this->getUser()->getTouched(),
			'epoch' => $config->get( MainConfigNames::CacheEpoch )
		];
		if ( $config->get( MainConfigNames::UseCdn ) ) {
			$modifiedTimes['sepoch'] = wfTimestamp( TS_MW, $this->getCdnCacheEpoch(
				time(),
				$config->get( MainConfigNames::CdnMaxAge )
			) );
		}
		$this->getHookRunner()->onOutputPageCheckLastModified( $modifiedTimes, $this );

		$maxModified = max( $modifiedTimes );
		$this->mLastModified = wfTimestamp( TS_RFC2822, $maxModified );

		$clientHeader = $this->getRequest()->getHeader( 'If-Modified-Since' );
		if ( $clientHeader === false ) {
			wfDebug( __METHOD__ . ": client did not send If-Modified-Since header", 'private' );
			return false;
		}

		# IE sends sizes after the date like this:
		# Wed, 20 Aug 2003 06:51:19 GMT; length=5202
		# this breaks strtotime().
		$clientHeader = preg_replace( '/;.*$/', '', $clientHeader );

		// E_STRICT system time warnings
		AtEase::suppressWarnings();
		$clientHeaderTime = strtotime( $clientHeader );
		AtEase::restoreWarnings();
		if ( !$clientHeaderTime ) {
			wfDebug( __METHOD__
				. ": unable to parse the client's If-Modified-Since header: $clientHeader" );
			return false;
		}
		$clientHeaderTime = wfTimestamp( TS_MW, $clientHeaderTime );

		# Make debug info
		$info = '';
		foreach ( $modifiedTimes as $name => $value ) {
			if ( $info !== '' ) {
				$info .= ', ';
			}
			$info .= "$name=" . wfTimestamp( TS_ISO_8601, $value );
		}

		wfDebug( __METHOD__ . ": client sent If-Modified-Since: " .
			wfTimestamp( TS_ISO_8601, $clientHeaderTime ), 'private' );
		wfDebug( __METHOD__ . ": effective Last-Modified: " .
			wfTimestamp( TS_ISO_8601, $maxModified ), 'private' );
		if ( $clientHeaderTime < $maxModified ) {
			wfDebug( __METHOD__ . ": STALE, $info", 'private' );
			return false;
		}

		# Not modified
		# Give a 304 Not Modified response code and disable body output
		wfDebug( __METHOD__ . ": NOT MODIFIED, $info", 'private' );
		ini_set( 'zlib.output_compression', 0 );
		$this->getRequest()->response()->statusHeader( 304 );
		$this->sendCacheControl();
		$this->disable();

		// Don't output a compressed blob when using ob_gzhandler;
		// it's technically against HTTP spec and seems to confuse
		// Firefox when the response gets split over two packets.
		wfResetOutputBuffers( false );

		return true;
	}

	/**
	 * @param int $reqTime Time of request (eg. now)
	 * @param int $maxAge Cache TTL in seconds
	 * @return int Timestamp
	 */
	private function getCdnCacheEpoch( $reqTime, $maxAge ) {
		// Ensure Last-Modified is never more than $wgCdnMaxAge in the past,
		// because even if the wiki page content hasn't changed since, static
		// resources may have changed (skin HTML, interface messages, urls, etc.)
		// and must roll-over in a timely manner (T46570)
		return $reqTime - $maxAge;
	}

	/**
	 * Override the last modified timestamp
	 *
	 * @param string $timestamp New timestamp, in a format readable by
	 *        wfTimestamp()
	 */
	public function setLastModified( $timestamp ) {
		$this->mLastModified = wfTimestamp( TS_RFC2822, $timestamp );
	}

	/**
	 * Set the robot policy for the page: <http://www.robotstxt.org/meta.html>
	 *
	 * @param string $policy The literal string to output as the contents of
	 *   the meta tag.  Will be parsed according to the spec and output in
	 *   standardized form.
	 */
	public function setRobotPolicy( $policy ) {
		$policy = Article::formatRobotPolicy( $policy );

		if ( isset( $policy['index'] ) ) {
			$this->setIndexPolicy( $policy['index'] );
		}
		if ( isset( $policy['follow'] ) ) {
			$this->setFollowPolicy( $policy['follow'] );
		}
	}

	/**
	 * Get the current robot policy for the page as a string in the form
	 * <index policy>,<follow policy>.
	 *
	 * @return string
	 */
	public function getRobotPolicy() {
		return "{$this->mIndexPolicy},{$this->mFollowPolicy}";
	}

	/**
	 * Format an array of robots options as a string of directives.
	 *
	 * @return string The robots policy options.
	 */
	private function formatRobotsOptions(): string {
		$options = $this->mRobotsOptions;
		// Check if options array has any non-integer keys.
		if ( count( array_filter( array_keys( $options ), 'is_string' ) ) > 0 ) {
			// Robots meta tags can have directives that are single strings or
			// have parameters that should be formatted like <directive>:<setting>.
			// If the options keys are strings, format them accordingly.
			// https://developers.google.com/search/docs/advanced/robots/robots_meta_tag
			array_walk( $options, static function ( &$value, $key ) {
				$value = is_string( $key ) ? "{$key}:{$value}" : "{$value}";
			} );
		}
		return implode( ',', $options );
	}

	/**
	 * Set the robots policy with options for the page.
	 *
	 * @since 1.38
	 * @param array $options An array of key-value pairs or a string
	 *   to populate the robots meta tag content attribute as a string.
	 */
	public function setRobotsOptions( array $options = [] ): void {
		$this->mRobotsOptions = array_merge( $this->mRobotsOptions, $options );
	}

	/**
	 * Get the robots policy content attribute for the page
	 * as a string in the form <index policy>,<follow policy>,<options>.
	 *
	 * @return string
	 */
	private function getRobotsContent(): string {
		$robotOptionString = $this->formatRobotsOptions();
		$robotArgs = ( $this->mIndexPolicy === 'index' &&
			$this->mFollowPolicy === 'follow' ) ?
			[] :
			[
				$this->mIndexPolicy,
				$this->mFollowPolicy,
			];
		if ( $robotOptionString ) {
			$robotArgs[] = $robotOptionString;
		}
		return implode( ',', $robotArgs );
	}

	/**
	 * Set the index policy for the page, but leave the follow policy un-
	 * touched.
	 *
	 * @param string $policy Either 'index' or 'noindex'.
	 */
	public function setIndexPolicy( $policy ) {
		$policy = trim( $policy );
		if ( in_array( $policy, [ 'index', 'noindex' ] ) ) {
			$this->mIndexPolicy = $policy;
		}
	}

	/**
	 * Get the current index policy for the page as a string.
	 *
	 * @return string
	 */
	public function getIndexPolicy() {
		return $this->mIndexPolicy;
	}

	/**
	 * Set the follow policy for the page, but leave the index policy un-
	 * touched.
	 *
	 * @param string $policy Either 'follow' or 'nofollow'.
	 */
	public function setFollowPolicy( $policy ) {
		$policy = trim( $policy );
		if ( in_array( $policy, [ 'follow', 'nofollow' ] ) ) {
			$this->mFollowPolicy = $policy;
		}
	}

	/**
	 * Get the current follow policy for the page as a string.
	 *
	 * @return string
	 */
	public function getFollowPolicy() {
		return $this->mFollowPolicy;
	}

	/**
	 * "HTML title" means the contents of "<title>".
	 * It is stored as plain, unescaped text and will be run through htmlspecialchars in the skin file.
	 *
	 * @param string|Message $name
	 */
	public function setHTMLTitle( $name ) {
		if ( $name instanceof Message ) {
			$this->mHTMLtitle = $name->setContext( $this->getContext() )->text();
		} else {
			$this->mHTMLtitle = $name;
		}
	}

	/**
	 * Return the "HTML title", i.e. the content of the "<title>" tag.
	 *
	 * @return string
	 */
	public function getHTMLTitle() {
		return $this->mHTMLtitle;
	}

	/**
	 * Set $mRedirectedFrom, the page which redirected us to the current page.
	 *
	 * @param PageReference $t
	 */
	public function setRedirectedFrom( PageReference $t ) {
		$this->mRedirectedFrom = $t;
	}

	/**
	 * "Page title" means the contents of \<h1\>. It is stored as a valid HTML
	 * fragment. This function allows good tags like \<sup\> in the \<h1\> tag,
	 * but not bad tags like \<script\>. This function automatically sets
	 * \<title\> to the same content as \<h1\> but with all tags removed. Bad
	 * tags that were escaped in \<h1\> will still be escaped in \<title\>, and
	 * good tags like \<i\> will be dropped entirely.
	 *
	 * @param string|Message $name The page title, either as HTML string or
	 *   as a message which will be formatted with FORMAT_TEXT to yield HTML.
	 *   Passing a Message is deprecated, since 1.41; please use
	 *   ::setPageTitleMsg() for that case instead.
	 * @param-taint $name tainted
	 * Phan-taint-check gets very confused by $name being either a string or a Message
	 */
	public function setPageTitle( $name ) {
		if ( $name instanceof Message ) {
			// T343849: passing a Message is deprecated and a warning will
			// eventually be emitted here.
			$name = $name->setContext( $this->getContext() )->text();
		}
		$this->setPageTitleInternal( $name );
	}

	/**
	 * "Page title" means the contents of \<h1\>. This message takes a
	 * Message, which will be formatted with FORMAT_ESCAPED to yield
	 * HTML.  Raw parameters to the message may contain some HTML
	 * tags; see ::setPageTitle() and Sanitizer::removeSomeTags() for
	 * details.  This function automatically sets \<title\> to the
	 * same content as \<h1\> but with all tags removed. Bad tags from
	 * "raw" parameters that were escaped in \<h1\> will still be
	 * escaped in \<title\>, and good tags like \<i\> will be dropped
	 * entirely.
	 *
	 * @param Message $msg The page title, as a message which will be
	 *   formatted with FORMAT_ESCAPED to yield HTML.
	 * @since 1.41
	 */
	public function setPageTitleMsg( Message $msg ): void {
		$this->setPageTitleInternal(
			$msg->setContext( $this->getContext() )->escaped()
		);
	}

	private function setPageTitleInternal( string $name ): void {
		# change "<script>foo&bar</script>" to "&lt;script&gt;foo&amp;bar&lt;/script&gt;"
		# but leave "<i>foobar</i>" alone
		$nameWithTags = Sanitizer::removeSomeTags( $name );
		$this->mPageTitle = $nameWithTags;

		# change "<i>foo&amp;bar</i>" to "foo&bar"
		$this->setHTMLTitle(
			$this->msg( 'pagetitle' )->plaintextParams( Sanitizer::stripAllTags( $nameWithTags ) )
				->inContentLanguage()
		);
	}

	/**
	 * Return the "page title", i.e. the content of the \<h1\> tag.
	 *
	 * @return string
	 */
	public function getPageTitle() {
		return $this->mPageTitle;
	}

	/**
	 * Same as page title but only contains name of the page, not any other text.
	 *
	 * @since 1.32
	 * @param string $html Page title text.
	 * @see OutputPage::setPageTitle
	 */
	public function setDisplayTitle( $html ) {
		$this->displayTitle = $html;
	}

	/**
	 * Returns page display title.
	 *
	 * Performs some normalization, but this not as strict the magic word.
	 *
	 * @since 1.32
	 * @return string HTML
	 */
	public function getDisplayTitle() {
		$html = $this->displayTitle;
		if ( $html === null ) {
			return htmlspecialchars( $this->getTitle()->getPrefixedText(), ENT_NOQUOTES );
		}

		return Sanitizer::removeSomeTags( $html );
	}

	/**
	 * Returns page display title without namespace prefix if possible.
	 *
	 * This method is unreliable and best avoided. (T314399)
	 *
	 * @since 1.32
	 * @return string HTML
	 */
	public function getUnprefixedDisplayTitle() {
		$service = MediaWikiServices::getInstance();
		$languageConverter = $service->getLanguageConverterFactory()
			->getLanguageConverter( $service->getContentLanguage() );
		$text = $this->getDisplayTitle();

		// Create a regexp with matching groups as placeholders for the namespace, separator and main text
		$pageTitleRegexp = "/^" . str_replace(
			preg_quote( '(.+?)', '/' ),
			'(.+?)',
			preg_quote( Parser::formatPageTitle( '(.+?)', '(.+?)', '(.+?)' ), '/' )
		) . "$/";
		$matches = [];
		if ( preg_match( $pageTitleRegexp, $text, $matches ) ) {
			// The regexp above could be manipulated by malicious user input,
			// sanitize the result just in case
			return Sanitizer::removeSomeTags( $matches[3] );
		}

		$nsPrefix = $languageConverter->convertNamespace(
			$this->getTitle()->getNamespace()
		) . ':';
		$prefix = preg_quote( $nsPrefix, '/' );

		return preg_replace( "/^$prefix/i", '', $text );
	}

	/**
	 * Set the Title object to use
	 *
	 * @param PageReference $t
	 */
	public function setTitle( PageReference $t ) {
		$t = Title::newFromPageReference( $t );

		// @phan-suppress-next-next-line PhanUndeclaredMethod
		// @fixme Not all implementations of IContextSource have this method!
		$this->getContext()->setTitle( $t );
	}

	/**
	 * Replace the subtitle with $str
	 *
	 * @param string|Message $str New value of the subtitle. String should be safe HTML.
	 */
	public function setSubtitle( $str ) {
		$this->clearSubtitle();
		$this->addSubtitle( $str );
	}

	/**
	 * Add $str to the subtitle
	 *
	 * @param string|Message $str String or Message to add to the subtitle. String should be safe HTML.
	 * @param-taint $str exec_html
	 */
	public function addSubtitle( $str ) {
		if ( $str instanceof Message ) {
			$this->mSubtitle[] = $str->setContext( $this->getContext() )->parse();
		} else {
			$this->mSubtitle[] = $str;
		}
	}

	/**
	 * Build message object for a subtitle containing a backlink to a page
	 *
	 * @since 1.25
	 * @param PageReference $page Title to link to
	 * @param array $query Array of additional parameters to include in the link
	 * @return Message
	 */
	public static function buildBacklinkSubtitle( PageReference $page, $query = [] ) {
		if ( $page instanceof PageRecord || $page instanceof Title ) {
			// Callers will typically have a PageRecord
			if ( $page->isRedirect() ) {
				$query['redirect'] = 'no';
			}
		} elseif ( $page->getNamespace() !== NS_SPECIAL ) {
			// We don't know whether it's a redirect, so add the parameter, just to be sure.
			$query['redirect'] = 'no';
		}

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		return wfMessage( 'backlinksubtitle' )
			->rawParams( $linkRenderer->makeLink( $page, null, [], $query ) );
	}

	/**
	 * Add a subtitle containing a backlink to a page
	 *
	 * @param PageReference $title Title to link to
	 * @param array $query Array of additional parameters to include in the link
	 */
	public function addBacklinkSubtitle( PageReference $title, $query = [] ) {
		$this->addSubtitle( self::buildBacklinkSubtitle( $title, $query ) );
	}

	/**
	 * Clear the subtitles
	 */
	public function clearSubtitle() {
		$this->mSubtitle = [];
	}

	/**
	 * @return string
	 */
	public function getSubtitle() {
		return implode( "<br />\n\t\t\t\t", $this->mSubtitle );
	}

	/**
	 * Set the page as printable, i.e. it'll be displayed with all
	 * print styles included
	 */
	public function setPrintable() {
		$this->mPrintable = true;
	}

	/**
	 * Return whether the page is "printable"
	 *
	 * @return bool
	 */
	public function isPrintable() {
		return $this->mPrintable;
	}

	/**
	 * Disable output completely, i.e. calling output() will have no effect
	 */
	public function disable() {
		$this->mDoNothing = true;
	}

	/**
	 * Return whether the output will be completely disabled
	 *
	 * @return bool
	 */
	public function isDisabled() {
		return $this->mDoNothing;
	}

	/**
	 * Show an "add new section" link?
	 *
	 * @return bool
	 */
	public function showNewSectionLink() {
		return $this->mNewSectionLink;
	}

	/**
	 * Forcibly hide the new section link?
	 *
	 * @return bool
	 */
	public function forceHideNewSectionLink() {
		return $this->mHideNewSectionLink;
	}

	/**
	 * Add or remove feed links in the page header
	 * This is mainly kept for backward compatibility, see OutputPage::addFeedLink()
	 * for the new version
	 * @see addFeedLink()
	 *
	 * @param bool $show True: add default feeds, false: remove all feeds
	 */
	public function setSyndicated( $show = true ) {
		if ( $show ) {
			$this->setFeedAppendQuery( false );
		} else {
			$this->mFeedLinks = [];
		}
	}

	/**
	 * Return effective list of advertised feed types
	 * @see addFeedLink()
	 *
	 * @return string[] Array of feed type names ( 'rss', 'atom' )
	 */
	protected function getAdvertisedFeedTypes() {
		if ( $this->getConfig()->get( MainConfigNames::Feed ) ) {
			return $this->getConfig()->get( MainConfigNames::AdvertisedFeedTypes );
		} else {
			return [];
		}
	}

	/**
	 * Add default feeds to the page header
	 * This is mainly kept for backward compatibility, see OutputPage::addFeedLink()
	 * for the new version
	 * @see addFeedLink()
	 *
	 * @param string|false $val Query to append to feed links or false to output
	 *        default links
	 */
	public function setFeedAppendQuery( $val ) {
		$this->mFeedLinks = [];

		foreach ( $this->getAdvertisedFeedTypes() as $type ) {
			$query = "feed=$type";
			if ( is_string( $val ) ) {
				$query .= '&' . $val;
			}
			$this->mFeedLinks[$type] = $this->getTitle()->getLocalURL( $query );
		}
	}

	/**
	 * Add a feed link to the page header
	 *
	 * @param string $format Feed type, should be a key of $wgFeedClasses
	 * @param string $href URL
	 */
	public function addFeedLink( $format, $href ) {
		if ( in_array( $format, $this->getAdvertisedFeedTypes() ) ) {
			$this->mFeedLinks[$format] = $href;
		}
	}

	/**
	 * Should we output feed links for this page?
	 * @return bool
	 */
	public function isSyndicated() {
		return count( $this->mFeedLinks ) > 0;
	}

	/**
	 * Return URLs for each supported syndication format for this page.
	 * @return array Associating format keys with URLs
	 */
	public function getSyndicationLinks() {
		return $this->mFeedLinks;
	}

	/**
	 * Will currently always return null
	 *
	 * @return null
	 */
	public function getFeedAppendQuery() {
		return $this->mFeedLinksAppendQuery;
	}

	/**
	 * Set whether the displayed content is related to the source of the
	 * corresponding article on the wiki
	 * Setting true will cause the change "article related" toggle to true
	 *
	 * @param bool $newVal
	 */
	public function setArticleFlag( $newVal ) {
		$this->mIsArticle = $newVal;
		if ( $newVal ) {
			$this->mIsArticleRelated = $newVal;
		}
	}

	/**
	 * Return whether the content displayed page is related to the source of
	 * the corresponding article on the wiki
	 *
	 * @return bool
	 */
	public function isArticle() {
		return $this->mIsArticle;
	}

	/**
	 * Set whether this page is related an article on the wiki
	 * Setting false will cause the change of "article flag" toggle to false
	 *
	 * @param bool $newVal
	 */
	public function setArticleRelated( $newVal ) {
		$this->mIsArticleRelated = $newVal;
		if ( !$newVal ) {
			$this->mIsArticle = false;
		}
	}

	/**
	 * Return whether this page is related an article on the wiki
	 *
	 * @return bool
	 */
	public function isArticleRelated() {
		return $this->mIsArticleRelated;
	}

	/**
	 * Set whether the standard copyright should be shown for the current page.
	 *
	 * @param bool $hasCopyright
	 */
	public function setCopyright( $hasCopyright ) {
		$this->mHasCopyright = $hasCopyright;
	}

	/**
	 * Return whether the standard copyright should be shown for the current page.
	 * By default, it is true for all articles but other pages
	 * can signal it by using setCopyright( true ).
	 *
	 * Used by SkinTemplate to decided whether to show the copyright.
	 *
	 * @return bool
	 */
	public function showsCopyright() {
		return $this->isArticle() || $this->mHasCopyright;
	}

	/**
	 * Add new language links
	 *
	 * @param string[] $newLinkArray Array of interwiki-prefixed (non DB key) titles
	 *                               (e.g. 'fr:Test page')
	 */
	public function addLanguageLinks( array $newLinkArray ) {
		$this->mLanguageLinks = array_merge( $this->mLanguageLinks, $newLinkArray );
	}

	/**
	 * Reset the language links and add new language links
	 *
	 * @param string[] $newLinkArray Array of interwiki-prefixed (non DB key) titles
	 *                               (e.g. 'fr:Test page')
	 */
	public function setLanguageLinks( array $newLinkArray ) {
		$this->mLanguageLinks = $newLinkArray;
	}

	/**
	 * Get the list of language links
	 *
	 * @return string[] Array of interwiki-prefixed (non DB key) titles (e.g. 'fr:Test page')
	 */
	public function getLanguageLinks() {
		return $this->mLanguageLinks;
	}

	/**
	 * Add an array of categories, with names in the keys
	 *
	 * @param array $categories Mapping category name => sort key
	 */
	public function addCategoryLinks( array $categories ) {
		if ( !$categories ) {
			return;
		}

		$res = $this->addCategoryLinksToLBAndGetResult( $categories );

		# Set all the values to 'normal'.
		$categories = array_fill_keys( array_keys( $categories ), 'normal' );
		$pageData = [];

		# Mark hidden categories
		foreach ( $res as $row ) {
			if ( isset( $row->pp_value ) ) {
				$categories[$row->page_title] = 'hidden';
			}
			// Page exists, cache results
			if ( isset( $row->page_id ) ) {
				$pageData[$row->page_title] = $row;
			}
		}

		# Add the remaining categories to the skin
		if ( $this->getHookRunner()->onOutputPageMakeCategoryLinks(
			$this, $categories, $this->mCategoryLinks )
		) {
			$services = MediaWikiServices::getInstance();
			$linkRenderer = $services->getLinkRenderer();
			$languageConverter = $services->getLanguageConverterFactory()
				->getLanguageConverter( $services->getContentLanguage() );
			foreach ( $categories as $category => $type ) {
				// array keys will cast numeric category names to ints, so cast back to string
				$category = (string)$category;
				$origcategory = $category;
				if ( array_key_exists( $category, $pageData ) ) {
					$title = Title::newFromRow( $pageData[$category] );
				} else {
					$title = Title::makeTitleSafe( NS_CATEGORY, $category );
				}
				if ( !$title ) {
					continue;
				}
				$languageConverter->findVariantLink( $category, $title, true );

				if ( $category != $origcategory && array_key_exists( $category, $categories ) ) {
					continue;
				}
				$text = $languageConverter->convertHtml( $title->getText() );
				$this->mCategories[$type][] = $title->getText();
				$this->mCategoryLinks[$type][] = $linkRenderer->makeLink( $title, new HtmlArmor( $text ) );
			}
		}
	}

	/**
	 * @param array $categories
	 * @return IResultWrapper
	 */
	protected function addCategoryLinksToLBAndGetResult( array $categories ) {
		# Add the links to a LinkBatch
		$arr = [ NS_CATEGORY => $categories ];
		$linkBatchFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
		$lb = $linkBatchFactory->newLinkBatch();
		$lb->setArray( $arr );

		# Fetch existence plus the hiddencat property
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$fields = array_merge(
			LinkCache::getSelectFields(),
			[ 'pp_value' ]
		);

		$res = $dbr->newSelectQueryBuilder()
			->select( $fields )
			->from( 'page' )
			->leftJoin( 'page_props', null, [
				'pp_propname' => 'hiddencat',
				'pp_page = page_id',
			] )
			->where( $lb->constructSet( 'page', $dbr ) )
			->caller( __METHOD__ )
			->fetchResultSet();

		# Add the results to the link cache
		$linkCache = MediaWikiServices::getInstance()->getLinkCache();
		$lb->addResultToCache( $linkCache, $res );

		return $res;
	}

	/**
	 * Reset the category links (but not the category list) and add $categories
	 *
	 * @param array $categories Mapping category name => sort key
	 */
	public function setCategoryLinks( array $categories ) {
		$this->mCategoryLinks = [];
		$this->addCategoryLinks( $categories );
	}

	/**
	 * Get the list of category links, in a 2-D array with the following format:
	 * $arr[$type][] = $link, where $type is either "normal" or "hidden" (for
	 * hidden categories) and $link a HTML fragment with a link to the category
	 * page
	 *
	 * @return string[][]
	 * @return-taint none
	 */
	public function getCategoryLinks() {
		return $this->mCategoryLinks;
	}

	/**
	 * Get the list of category names this page belongs to.
	 *
	 * @param string $type The type of categories which should be returned. Possible values:
	 *  * all: all categories of all types
	 *  * hidden: only the hidden categories
	 *  * normal: all categories, except hidden categories
	 * @return string[]
	 */
	public function getCategories( $type = 'all' ) {
		if ( $type === 'all' ) {
			$allCategories = [];
			foreach ( $this->mCategories as $categories ) {
				$allCategories = array_merge( $allCategories, $categories );
			}
			return $allCategories;
		}
		if ( !isset( $this->mCategories[$type] ) ) {
			throw new InvalidArgumentException( 'Invalid category type given: ' . $type );
		}
		return $this->mCategories[$type];
	}

	/**
	 * Add an array of indicators, with their identifiers as array
	 * keys and HTML contents as values.
	 *
	 * In case of duplicate keys, existing values are overwritten.
	 *
	 * @note External code which calls this method should ensure that
	 * any indicators sourced from parsed wikitext are wrapped with
	 * the appropriate class; see note in ::getIndicators().
	 *
	 * @param string[] $indicators
	 * @param-taint $indicators exec_html
	 * @since 1.25
	 */
	public function setIndicators( array $indicators ) {
		$this->mIndicators = $indicators + $this->mIndicators;
		// Keep ordered by key
		ksort( $this->mIndicators );
	}

	/**
	 * Get the indicators associated with this page.
	 *
	 * The array will be internally ordered by item keys.
	 *
	 * @return string[] Keys: identifiers, values: HTML contents
	 * @since 1.25
	 */
	public function getIndicators(): array {
		// Note that some -- but not all -- indicators will be wrapped
		// with a class appropriate for user-generated wikitext content
		// (usually .mw-parser-output). The exceptions would be an
		// indicator added via ::addHelpLink() below, which adds content
		// which don't come from the parser and is not user-generated;
		// and any indicators added by extensions which may call
		// OutputPage::setIndicators() directly.  In the latter case the
		// caller is responsible for wrapping any parser-generated
		// indicators.
		return $this->mIndicators;
	}

	/**
	 * Adds help link with an icon via page indicators.
	 * Link target can be overridden by a local message containing a wikilink:
	 * the message key is: lowercase action or special page name + '-helppage'.
	 * @param string $to Target MediaWiki.org page title or encoded URL.
	 * @param bool $overrideBaseUrl Whether $url is a full URL, to avoid MediaWiki.org.
	 * @since 1.25
	 */
	public function addHelpLink( $to, $overrideBaseUrl = false ) {
		$this->addModuleStyles( 'mediawiki.helplink' );
		$text = $this->msg( 'helppage-top-gethelp' )->escaped();

		if ( $overrideBaseUrl ) {
			$helpUrl = $to;
		} else {
			$toUrlencoded = wfUrlencode( str_replace( ' ', '_', $to ) );
			$helpUrl = "https://www.mediawiki.org/wiki/Special:MyLanguage/$toUrlencoded";
		}

		$link = Html::rawElement(
			'a',
			[
				'href' => $helpUrl,
				'target' => '_blank',
				'class' => 'mw-helplink',
			],
			$text
		);

		// See note in ::getIndicators() above -- unlike wikitext-generated
		// indicators which come from ParserOutput, this indicator will not
		// be wrapped.
		$this->setIndicators( [ 'mw-helplink' => $link ] );
	}

	/**
	 * Do not allow scripts which can be modified by wiki users to load on this page;
	 * only allow scripts bundled with, or generated by, the software.
	 * Site-wide styles are controlled by a config setting, since they can be
	 * used to create a custom skin/theme, but not user-specific ones.
	 *
	 * @todo this should be given a more accurate name
	 */
	public function disallowUserJs() {
		$this->reduceAllowedModules(
			RL\Module::TYPE_SCRIPTS,
			RL\Module::ORIGIN_CORE_INDIVIDUAL
		);

		// Site-wide styles are controlled by a config setting, see T73621
		// for background on why. User styles are never allowed.
		if ( $this->getConfig()->get( MainConfigNames::AllowSiteCSSOnRestrictedPages ) ) {
			$styleOrigin = RL\Module::ORIGIN_USER_SITEWIDE;
		} else {
			$styleOrigin = RL\Module::ORIGIN_CORE_INDIVIDUAL;
		}
		$this->reduceAllowedModules(
			RL\Module::TYPE_STYLES,
			$styleOrigin
		);
	}

	/**
	 * Show what level of JavaScript / CSS untrustworthiness is allowed on this page
	 * @see RL\Module::$origin
	 * @param string $type RL\Module TYPE_ constant
	 * @return int Module ORIGIN_ class constant
	 */
	public function getAllowedModules( $type ) {
		if ( $type == RL\Module::TYPE_COMBINED ) {
			return min( array_values( $this->mAllowedModules ) );
		} else {
			return $this->mAllowedModules[$type] ?? RL\Module::ORIGIN_ALL;
		}
	}

	/**
	 * Limit the highest level of CSS/JS untrustworthiness allowed.
	 *
	 * If passed the same or a higher level than the current level of untrustworthiness set, the
	 * level will remain unchanged.
	 *
	 * @param string $type
	 * @param int $level RL\Module class constant
	 */
	public function reduceAllowedModules( $type, $level ) {
		$this->mAllowedModules[$type] = min( $this->getAllowedModules( $type ), $level );
	}

	/**
	 * Prepend $text to the body HTML
	 *
	 * @param string $text HTML
	 * @param-taint $text exec_html
	 */
	public function prependHTML( $text ) {
		$this->mBodytext = $text . $this->mBodytext;
	}

	/**
	 * Append $text to the body HTML
	 *
	 * @param string $text HTML
	 * @param-taint $text exec_html
	 */
	public function addHTML( $text ) {
		$this->mBodytext .= $text;
	}

	/**
	 * Shortcut for adding an Html::element via addHTML.
	 *
	 * @since 1.19
	 *
	 * @param string $element
	 * @param array $attribs
	 * @param string $contents
	 */
	public function addElement( $element, array $attribs = [], $contents = '' ) {
		$this->addHTML( Html::element( $element, $attribs, $contents ) );
	}

	/**
	 * Clear the body HTML
	 */
	public function clearHTML() {
		$this->mBodytext = '';
	}

	/**
	 * Get the body HTML
	 *
	 * @return string HTML
	 */
	public function getHTML() {
		return $this->mBodytext;
	}

	/**
	 * Get/set the ParserOptions object to use for wikitext parsing
	 *
	 * @return ParserOptions
	 */
	public function parserOptions() {
		if ( !$this->mParserOptions ) {
			if ( !$this->getUser()->isSafeToLoad() ) {
				// Context user isn't unstubbable yet, so don't try to get a
				// ParserOptions for it. And don't cache this ParserOptions
				// either.
				$po = ParserOptions::newFromAnon();
				$po->setAllowUnsafeRawHtml( false );
				return $po;
			}

			$this->mParserOptions = ParserOptions::newFromContext( $this->getContext() );
			$this->mParserOptions->setAllowUnsafeRawHtml( false );
		}

		return $this->mParserOptions;
	}

	/**
	 * Set the revision ID which will be seen by the wiki text parser
	 * for things such as embedded {{REVISIONID}} variable use.
	 *
	 * @param int|null $revid A positive integer, or null
	 * @return mixed Previous value
	 */
	public function setRevisionId( $revid ) {
		$val = $revid === null ? null : intval( $revid );
		return wfSetVar( $this->mRevisionId, $val, true );
	}

	/**
	 * Get the displayed revision ID
	 *
	 * @return int|null
	 */
	public function getRevisionId() {
		return $this->mRevisionId;
	}

	/**
	 * Set whether the revision displayed (as set in ::setRevisionId())
	 * is the latest revision of the page.
	 *
	 * @param bool $isCurrent
	 */
	public function setRevisionIsCurrent( bool $isCurrent ): void {
		$this->mRevisionIsCurrent = $isCurrent;
	}

	/**
	 * Whether the revision displayed is the latest revision of the page
	 *
	 * @since 1.34
	 * @return bool
	 */
	public function isRevisionCurrent(): bool {
		return $this->mRevisionId == 0 || (
			$this->mRevisionIsCurrent ?? (
				$this->mRevisionId == $this->getTitle()->getLatestRevID()
			)
		);
	}

	/**
	 * Set the timestamp of the revision which will be displayed. This is used
	 * to avoid a extra DB call in SkinComponentFooter::lastModified().
	 *
	 * @param string|null $timestamp
	 * @return mixed Previous value
	 */
	public function setRevisionTimestamp( $timestamp ) {
		return wfSetVar( $this->mRevisionTimestamp, $timestamp, true );
	}

	/**
	 * Get the timestamp of displayed revision.
	 * This will be null if not filled by setRevisionTimestamp().
	 *
	 * @return string|null
	 */
	public function getRevisionTimestamp() {
		return $this->mRevisionTimestamp;
	}

	/**
	 * Set the displayed file version
	 *
	 * @param File|null $file
	 * @return mixed Previous value
	 */
	public function setFileVersion( $file ) {
		$val = null;
		if ( $file instanceof File && $file->exists() ) {
			$val = [ 'time' => $file->getTimestamp(), 'sha1' => $file->getSha1() ];
		}
		return wfSetVar( $this->mFileVersion, $val, true );
	}

	/**
	 * Get the displayed file version
	 *
	 * @return array|null ('time' => MW timestamp, 'sha1' => sha1)
	 */
	public function getFileVersion() {
		return $this->mFileVersion;
	}

	/**
	 * Get the templates used on this page
	 *
	 * @return array<int,array<string,int>> (namespace => dbKey => revId)
	 * @since 1.18
	 */
	public function getTemplateIds() {
		return $this->mTemplateIds;
	}

	/**
	 * Get the files used on this page
	 *
	 * @return array [ dbKey => [ 'time' => MW timestamp or null, 'sha1' => sha1 or '' ] ]
	 * @since 1.18
	 */
	public function getFileSearchOptions() {
		return $this->mImageTimeKeys;
	}

	/**
	 * Convert wikitext *in the user interface language* to HTML and
	 * add it to the buffer. The result will not be
	 * language-converted, as user interface messages are already
	 * localized into a specific variant.  Assumes that the current
	 * page title will be used if optional $title is not
	 * provided. Output will be tidy.
	 *
	 * @param string $text Wikitext in the user interface language
	 * @param bool $linestart Is this the start of a line? (Defaults to true)
	 * @param PageReference|null $title Optional title to use; default of `null`
	 *   means use current page title.
	 * @since 1.32
	 */
	public function addWikiTextAsInterface(
		$text, $linestart = true, PageReference $title = null
	) {
		$title ??= $this->getTitle();
		if ( !$title ) {
			throw new RuntimeException( 'Title is null' );
		}
		$this->addWikiTextTitleInternal( $text, $title, $linestart, /*interface*/true );
	}

	/**
	 * Convert wikitext *in the user interface language* to HTML and
	 * add it to the buffer with a `<div class="$wrapperClass">`
	 * wrapper.  The result will not be language-converted, as user
	 * interface messages as already localized into a specific
	 * variant.  The $text will be parsed in start-of-line context.
	 * Output will be tidy.
	 *
	 * @param string $wrapperClass The class attribute value for the <div>
	 *   wrapper in the output HTML
	 * @param string $text Wikitext in the user interface language
	 * @since 1.32
	 */
	public function wrapWikiTextAsInterface(
		$wrapperClass, $text
	) {
		$this->addWikiTextTitleInternal(
			$text, $this->getTitle(),
			/*linestart*/true, /*interface*/true,
			$wrapperClass
		);
	}

	/**
	 * Convert wikitext *in the page content language* to HTML and add
	 * it to the buffer.  The result with be language-converted to the
	 * user's preferred variant.  Assumes that the current page title
	 * will be used if optional $title is not provided. Output will be
	 * tidy.
	 *
	 * @param string $text Wikitext in the page content language
	 * @param bool $linestart Is this the start of a line? (Defaults to true)
	 * @param PageReference|null $title Optional title to use; default of `null`
	 *   means use current page title.
	 * @since 1.32
	 */
	public function addWikiTextAsContent(
		$text, $linestart = true, PageReference $title = null
	) {
		$title ??= $this->getTitle();
		if ( !$title ) {
			throw new RuntimeException( 'Title is null' );
		}
		$this->addWikiTextTitleInternal( $text, $title, $linestart, /*interface*/false );
	}

	/**
	 * Add wikitext with a custom Title object.
	 * Output is unwrapped.
	 *
	 * @param string $text Wikitext
	 * @param PageReference $title
	 * @param bool $linestart Is this the start of a line?@param
	 * @param bool $interface Whether it is an interface message
	 *   (for example disables conversion)
	 * @param string|null $wrapperClass if not empty, wraps the output in
	 *   a `<div class="$wrapperClass">`
	 */
	private function addWikiTextTitleInternal(
		$text, PageReference $title, $linestart, $interface, $wrapperClass = null
	) {
		$parserOutput = $this->parseInternal(
			$text, $title, $linestart, $interface
		);

		$this->addParserOutput( $parserOutput, [
			'enableSectionEditLinks' => false,
			'wrapperDivClass' => $wrapperClass ?? '',
		] );
	}

	/**
	 * Adds Table of Contents data to OutputPage from ParserOutput
	 * @param TOCData $tocData
	 * @internal For use by Article.php
	 */
	public function setTOCData( TOCData $tocData ) {
		$this->tocData = $tocData;
	}

	/**
	 * @internal For usage in Skin::getTOCData() only.
	 * @return ?TOCData Table of Contents data, or
	 *   null if OutputPage::setTOCData() has not been called.
	 */
	public function getTOCData(): ?TOCData {
		return $this->tocData;
	}

	/**
	 * @internal Will be replaced by direct access to
	 *  ParserOutput::getOutputFlag()
	 * @param string $name A flag name from ParserOutputFlags
	 * @return bool
	 */
	public function getOutputFlag( string $name ): bool {
		return isset( $this->mOutputFlags[$name] );
	}

	/**
	 * @internal For use by ViewAction/Article only
	 * @since 1.42
	 * @param Bcp47Code $lang
	 */
	public function setContentLangForJS( Bcp47Code $lang ): void {
		$this->contentLang = MediaWikiServices::getInstance()->getLanguageFactory()
			->getLanguage( $lang );
	}

	/**
	 * Which language getJSVars should use
	 *
	 * Use of this is strongly discouraged in favour of ParserOutput::getLanguage(),
	 * and should not be needed in most cases given that ParserOutput::getText()
	 * already takes care of 'lang' and 'dir' attributes.
	 *
	 * Consider whether RequestContext::getLanguage (e.g. OutputPage::getLanguage
	 * or Skin::getLanguage) or MediaWikiServices::getContentLanguage is more
	 * appropiate first for your use case.
	 *
	 * @since 1.42
	 * @return Language
	 */
	private function getContentLangForJS(): Language {
		if ( !$this->contentLang ) {
			// If this is not set, then we're likely not on in a request that renders page content
			// (e.g. ViewAction or ApiParse), but rather a different Action or SpecialPage.
			// In that case there isn't a main ParserOutput object to represent the page or output.
			// But, the skin and frontend code mostly don't make this distinction, and so we still
			// need to return something for mw.config.
			//
			// For historical reasons, the expectation is that:
			// * on a SpecialPage, we return the language for the content area just like on a
			//   page view. SpecialPage content is localised, and so this is the user language.
			// * on an Action about a WikiPage, we return the language that content would have
			//   been shown in, if this were a page view. This is generally the page language
			//   as stored in the database, except adapted to the current user (e.g. in case of
			//   translated pages or a language variant preference)
			//
			// This mess was centralised to here in 2023 (T341244).
			$title = $this->getTitle();
			if ( $title->isSpecialPage() ) {
				// Special pages render in the interface language, based on request context.
				// If the user's preference (or request parameter) specifies a variant,
				// the content may have been converted to the user's language variant.
				$pageLang = $this->getLanguage();
			} else {
				wfDebug( __METHOD__ . ' has to guess ParserOutput language' );
				// Guess what Article::getParserOutput and ParserOptions::optionsHash() would decide
				// on a page view:
				//
				// - Pages may have a custom page_lang set in the database,
				//   via Title::getPageLanguage/Title::getDbPageLanguage
				//
				// - Interface messages (NS_MEDIAWIKI) render based on their subpage,
				//   via Title::getPageLanguage/ContentHandler::getPageLanguage/MessageCache::figureMessage
				//
				// - Otherwise, pages are assumed to be in the wiki's default content language.
				//   via Title::getPageLanguage/ContentHandler::getPageLanguage/MediaWikiServices::getContentLanguage
				$pageLang = $title->getPageLanguage();
			}
			if ( $title->getNamespace() !== NS_MEDIAWIKI ) {
				$services = MediaWikiServices::getInstance();
				$langConv = $services->getLanguageConverterFactory()->getLanguageConverter( $pageLang );
				// NOTE: LanguageConverter::getPreferredVariant inspects global RequestContext.
				// This usually returns $pageLang unchanged.
				$variant = $langConv->getPreferredVariant();
				if ( $pageLang->getCode() !== $variant ) {
					$pageLang = $services->getLanguageFactory()->getLanguage( $variant );
				}
			}
			$this->contentLang = $pageLang;
		}
		return $this->contentLang;
	}

	/**
	 * Add all metadata associated with a ParserOutput object, but without the actual HTML. This
	 * includes categories, language links, ResourceLoader modules, effects of certain magic words,
	 * and so on.  It does *not* include section information.
	 *
	 * @since 1.24
	 * @param ParserOutput $parserOutput
	 */
	public function addParserOutputMetadata( ParserOutput $parserOutput ) {
		// T301020 This should eventually use the standard "merge ParserOutput"
		// function between $parserOutput and $this->metadata.
		$this->mLanguageLinks =
			array_merge( $this->mLanguageLinks, $parserOutput->getLanguageLinks() );
		$this->addCategoryLinks( $parserOutput->getCategoryMap() );

		// Parser-generated indicators get wrapped like other parser output.
		$wrapClass = $parserOutput->getWrapperDivClass();
		$result = [];
		foreach ( $parserOutput->getIndicators() as $name => $html ) {
			if ( $html !== '' && $wrapClass !== '' ) {
				$html = Html::rawElement( 'div', [ 'class' => $wrapClass ], $html );
			}
			$result[$name] = $html;
		}
		$this->setIndicators( $result );

		$tocData = $parserOutput->getTOCData();
		// Do not override existing TOC data if the new one is empty (T307256#8817705)
		// TODO: Invent a way to merge TOCs from multiple outputs (T327429)
		if ( $tocData !== null && ( $this->tocData === null || count( $tocData->getSections() ) > 0 ) ) {
			$this->setTOCData( $tocData );
		}

		// FIXME: Best practice is for OutputPage to be an accumulator, as
		// addParserOutputMetadata() may be called multiple times, but the
		// following lines overwrite any previous data.  These should
		// be migrated to an injection pattern. (T301020, T300979)
		// (Note that OutputPage::getOutputFlag() also contains this
		// information, with flags from each $parserOutput all OR'ed together.)
		$this->mNewSectionLink = $parserOutput->getNewSection();
		$this->mHideNewSectionLink = $parserOutput->getHideNewSection();
		$this->mNoGallery = $parserOutput->getNoGallery();

		if ( !$parserOutput->isCacheable() ) {
			$this->disableClientCache();
		}
		$this->addHeadItems( $parserOutput->getHeadItems() );
		$this->addModules( $parserOutput->getModules() );
		$this->addModuleStyles( $parserOutput->getModuleStyles() );
		$this->addJsConfigVars( $parserOutput->getJsConfigVars() );
		$this->mPreventClickjacking = $this->mPreventClickjacking
			|| $parserOutput->getPreventClickjacking();
		$scriptSrcs = $parserOutput->getExtraCSPScriptSrcs();
		foreach ( $scriptSrcs as $src ) {
			$this->getCSP()->addScriptSrc( $src );
		}
		$defaultSrcs = $parserOutput->getExtraCSPDefaultSrcs();
		foreach ( $defaultSrcs as $src ) {
			$this->getCSP()->addDefaultSrc( $src );
		}
		$styleSrcs = $parserOutput->getExtraCSPStyleSrcs();
		foreach ( $styleSrcs as $src ) {
			$this->getCSP()->addStyleSrc( $src );
		}

		// If $wgImagePreconnect is true, and if the output contains images, give the user-agent
		// a hint about a remote hosts from which images may be served. Launched in T123582.
		if ( $this->getConfig()->get( MainConfigNames::ImagePreconnect ) && count( $parserOutput->getImages() ) ) {
			$preconnect = [];
			// Optimization: Instead of processing each image, assume that wikis either serve both
			// foreign and local from the same remote hostname (e.g. public wikis at WMF), or that
			// foreign images are common enough to be worth the preconnect (e.g. private wikis).
			$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
			$repoGroup->forEachForeignRepo( static function ( $repo ) use ( &$preconnect ) {
				$preconnect[] = $repo->getZoneUrl( 'thumb' );
			} );
			// Consider both foreign and local repos. While LocalRepo by default uses a relative
			// path on the same domain, wiki farms may configure it to use a dedicated hostname.
			$preconnect[] = $repoGroup->getLocalRepo()->getZoneUrl( 'thumb' );
			foreach ( $preconnect as $url ) {
				$host = parse_url( $url, PHP_URL_HOST );
				// It is expected that file URLs are often path-only, without hostname (T317329).
				if ( $host ) {
					$this->addLink( [ 'rel' => 'preconnect', 'href' => '//' . $host ] );
					break;
				}
			}
		}

		// Template versioning...
		foreach ( (array)$parserOutput->getTemplateIds() as $ns => $dbks ) {
			if ( isset( $this->mTemplateIds[$ns] ) ) {
				$this->mTemplateIds[$ns] = $dbks + $this->mTemplateIds[$ns];
			} else {
				$this->mTemplateIds[$ns] = $dbks;
			}
		}
		// File versioning...
		foreach ( (array)$parserOutput->getFileSearchOptions() as $dbk => $data ) {
			$this->mImageTimeKeys[$dbk] = $data;
		}

		// Enable OOUI if requested via ParserOutput
		if ( $parserOutput->getEnableOOUI() ) {
			$this->enableOOUI();
		}

		// Include parser limit report
		// FIXME: This should append, rather than overwrite, or else this
		// data should be injected into the OutputPage like is done for the
		// other page-level things (like OutputPage::setTOCData()).
		if ( !$this->limitReportJSData ) {
			$this->limitReportJSData = $parserOutput->getLimitReportJSData();
		}

		// Link flags are ignored for now, but may in the future be
		// used to mark individual language links.
		$linkFlags = [];
		$this->getHookRunner()->onLanguageLinks( $this->getTitle(), $this->mLanguageLinks, $linkFlags );

		$this->getHookRunner()->onOutputPageParserOutput( $this, $parserOutput );

		// This check must be after 'OutputPageParserOutput' runs in addParserOutputMetadata
		// so that extensions may modify ParserOutput to toggle TOC.
		// This cannot be moved to addParserOutputText because that is not
		// called by EditPage for Preview.

		// ParserOutputFlags::SHOW_TOC is used to indicate whether the TOC
		// should be shown (or hidden) in the output.
		$this->mEnableTOC = $this->mEnableTOC ||
			$parserOutput->getOutputFlag( ParserOutputFlags::SHOW_TOC );
		// Uniform handling of all boolean flags: they are OR'ed together
		// (See ParserOutput::collectMetadata())
		$flags =
			array_flip( $parserOutput->getAllFlags() ) +
			array_flip( ParserOutputFlags::cases() );
		foreach ( $flags as $name => $ignore ) {
			if ( $parserOutput->getOutputFlag( $name ) ) {
				$this->mOutputFlags[$name] = true;
			}
		}
	}

	private function getParserOutputText( ParserOutput $parserOutput, array $poOptions = [] ): string {
		// Add default options from the skin
		$skin = $this->getSkin();
		$skinOptions = $skin->getOptions();
		$poOptions += [
			'skin' => $skin,
			'injectTOC' => $skinOptions['toc'],
		];
		// Note: this path absolutely expects $parserOutput to be mutated by getText, see T353257
		return $parserOutput->getText( $poOptions );
	}

	/**
	 * Add the HTML and enhancements for it (like ResourceLoader modules) associated with a
	 * ParserOutput object, without any other metadata.
	 *
	 * @since 1.24
	 * @param ParserOutput $parserOutput
	 * @param array $poOptions Options to ParserOutput::getText()
	 */
	public function addParserOutputContent( ParserOutput $parserOutput, $poOptions = [] ) {
		$text = $this->getParserOutputText( $parserOutput, $poOptions );
		$this->addParserOutputText( $text, $poOptions );

		$this->addModules( $parserOutput->getModules() );
		$this->addModuleStyles( $parserOutput->getModuleStyles() );

		$this->addJsConfigVars( $parserOutput->getJsConfigVars() );
	}

	/**
	 * Add the HTML associated with a ParserOutput object, without any metadata.
	 *
	 * @internal For local use only
	 * @param string|ParserOutput $text
	 * @param array $poOptions Options to ParserOutput::getText()
	 */
	public function addParserOutputText( $text, $poOptions = [] ) {
		if ( $text instanceof ParserOutput ) {
			wfDeprecated( __METHOD__ . ' with ParserOutput as first arg', '1.42' );
			$text = $this->getParserOutputText( $text, $poOptions );
		}
		$this->getHookRunner()->onOutputPageBeforeHTML( $this, $text );
		$this->addHTML( $text );
	}

	/**
	 * Add everything from a ParserOutput object.
	 *
	 * @param ParserOutput $parserOutput
	 * @param array $poOptions Options to ParserOutput::getText()
	 */
	public function addParserOutput( ParserOutput $parserOutput, $poOptions = [] ) {
		$text = $this->getParserOutputText( $parserOutput, $poOptions );
		$this->addParserOutputMetadata( $parserOutput );
		$this->addParserOutputText( $text, $poOptions );
	}

	/**
	 * Add the output of a QuickTemplate to the output buffer
	 *
	 * @param \QuickTemplate &$template
	 */
	public function addTemplate( &$template ) {
		$this->addHTML( $template->getHTML() );
	}

	/**
	 * Parse wikitext *in the page content language* and return the HTML.
	 * The result will be language-converted to the user's preferred variant.
	 * Output will be tidy.
	 *
	 * @param string $text Wikitext in the page content language
	 * @param bool $linestart Is this the start of a line? (Defaults to true)
	 * @return string HTML
	 * @since 1.32
	 */
	public function parseAsContent( $text, $linestart = true ) {
		return $this->parseInternal(
			$text, $this->getTitle(), $linestart, /*interface*/false
		)->getText( [
			'allowTOC' => false,
			'enableSectionEditLinks' => false,
			'wrapperDivClass' => '',
			'userLang' => $this->getContext()->getLanguage(),
		] );
	}

	/**
	 * Parse wikitext *in the user interface language* and return the HTML.
	 * The result will not be language-converted, as user interface messages
	 * are already localized into a specific variant.
	 * Output will be tidy.
	 *
	 * @param string $text Wikitext in the user interface language
	 * @param bool $linestart Is this the start of a line? (Defaults to true)
	 * @return string HTML
	 * @since 1.32
	 */
	public function parseAsInterface( $text, $linestart = true ) {
		return $this->parseInternal(
			$text, $this->getTitle(), $linestart, /*interface*/true
		)->getText( [
			'allowTOC' => false,
			'enableSectionEditLinks' => false,
			'wrapperDivClass' => '',
			'userLang' => $this->getContext()->getLanguage(),
		] );
	}

	/**
	 * Parse wikitext *in the user interface language*, strip
	 * paragraph wrapper, and return the HTML.
	 * The result will not be language-converted, as user interface messages
	 * are already localized into a specific variant.
	 * Output will be tidy.  Outer paragraph wrapper will only be stripped
	 * if the result is a single paragraph.
	 *
	 * @param string $text Wikitext in the user interface language
	 * @param bool $linestart Is this the start of a line? (Defaults to true)
	 * @return string HTML
	 * @since 1.32
	 */
	public function parseInlineAsInterface( $text, $linestart = true ) {
		return Parser::stripOuterParagraph(
			$this->parseAsInterface( $text, $linestart )
		);
	}

	/**
	 * Parse wikitext and return the HTML (internal implementation helper)
	 *
	 * @param string $text
	 * @param PageReference $title The title to use
	 * @param bool $linestart Is this the start of a line?
	 * @param bool $interface Use interface language (instead of content language) while parsing
	 *   language sensitive magic words like GRAMMAR and PLURAL.  This also disables
	 *   LanguageConverter.
	 * @return ParserOutput
	 */
	private function parseInternal( $text, $title, $linestart, $interface ) {
		if ( $title === null ) {
			throw new RuntimeException( 'Empty $mTitle in ' . __METHOD__ );
		}
		$popts = $this->parserOptions();

		$oldInterface = $popts->setInterfaceMessage( (bool)$interface );

		$parserOutput = MediaWikiServices::getInstance()->getParserFactory()->getInstance()
			->parse(
				$text, $title, $popts,
				$linestart, true, $this->mRevisionId
			);

		$popts->setInterfaceMessage( $oldInterface );

		return $parserOutput;
	}

	/**
	 * Set the value of the "s-maxage" part of the "Cache-control" HTTP header
	 *
	 * @param int $maxage Maximum cache time on the CDN, in seconds.
	 */
	public function setCdnMaxage( $maxage ) {
		$this->mCdnMaxage = min( $maxage, $this->mCdnMaxageLimit );
	}

	/**
	 * Set the value of the "s-maxage" part of the "Cache-control" HTTP header to $maxage if that is
	 * lower than the current s-maxage.  Either way, $maxage is now an upper limit on s-maxage, so
	 * that future calls to setCdnMaxage() will no longer be able to raise the s-maxage above
	 * $maxage.
	 *
	 * @param int $maxage Maximum cache time on the CDN, in seconds
	 * @since 1.27
	 */
	public function lowerCdnMaxage( $maxage ) {
		$this->mCdnMaxageLimit = min( $maxage, $this->mCdnMaxageLimit );
		$this->setCdnMaxage( $this->mCdnMaxage );
	}

	/**
	 * Get TTL in [$minTTL,$maxTTL] and pass it to lowerCdnMaxage()
	 *
	 * This sets and returns $minTTL if $mtime is false or null. Otherwise,
	 * the TTL is higher the older the $mtime timestamp is. Essentially, the
	 * TTL is 90% of the age of the object, subject to the min and max.
	 *
	 * @param string|int|float|bool|null $mtime Last-Modified timestamp
	 * @param int $minTTL Minimum TTL in seconds [default: 1 minute]
	 * @param int $maxTTL Maximum TTL in seconds [default: $wgCdnMaxAge]
	 * @since 1.28
	 */
	public function adaptCdnTTL( $mtime, $minTTL = 0, $maxTTL = 0 ) {
		$minTTL = $minTTL ?: ExpirationAwareness::TTL_MINUTE;
		$maxTTL = $maxTTL ?: $this->getConfig()->get( MainConfigNames::CdnMaxAge );

		if ( $mtime === null || $mtime === false ) {
			return; // entity does not exist
		}

		$age = MWTimestamp::time() - (int)wfTimestamp( TS_UNIX, $mtime );
		$adaptiveTTL = max( 0.9 * $age, $minTTL );
		$adaptiveTTL = min( $adaptiveTTL, $maxTTL );

		$this->lowerCdnMaxage( (int)$adaptiveTTL );
	}

	/**
	 * Do not send nocache headers
	 */
	public function enableClientCache(): void {
		$this->mEnableClientCache = true;
	}

	/**
	 * Force the page to send nocache headers
	 * @since 1.38
	 */
	public function disableClientCache(): void {
		$this->mEnableClientCache = false;
	}

	/**
	 * Whether the output might become publicly cached.
	 *
	 * @since 1.34
	 * @return bool
	 */
	public function couldBePublicCached() {
		if ( !$this->cacheIsFinal ) {
			// - The entry point handles its own caching and/or doesn't use OutputPage.
			//   (such as load.php, or MediaWiki\Rest\EntryPoint).
			//
			// - Or, we haven't finished processing the main part of the request yet
			//   (e.g. Action::show, SpecialPage::execute), and the state may still
			//   change via enableClientCache().
			return true;
		}
		// e.g. various error-type pages disable all client caching
		return $this->mEnableClientCache;
	}

	/**
	 * Set the expectation that cache control will not change after this point.
	 *
	 * This should be called after the main processing logic has completed
	 * (e.g. Action::show or SpecialPage::execute), but may be called
	 * before Skin output has started (OutputPage::output).
	 *
	 * @since 1.34
	 */
	public function considerCacheSettingsFinal() {
		$this->cacheIsFinal = true;
	}

	/**
	 * Get the list of cookie names that will influence the cache
	 *
	 * @return array
	 */
	public function getCacheVaryCookies() {
		if ( self::$cacheVaryCookies === null ) {
			$config = $this->getConfig();
			self::$cacheVaryCookies = array_values( array_unique( array_merge(
				SessionManager::singleton()->getVaryCookies(),
				[
					'forceHTTPS',
				],
				$config->get( MainConfigNames::CacheVaryCookies )
			) ) );
			$this->getHookRunner()->onGetCacheVaryCookies( $this, self::$cacheVaryCookies );
		}
		return self::$cacheVaryCookies;
	}

	/**
	 * Check if the request has a cache-varying cookie header
	 * If it does, it's very important that we don't allow public caching
	 *
	 * @return bool
	 */
	public function haveCacheVaryCookies() {
		$request = $this->getRequest();
		foreach ( $this->getCacheVaryCookies() as $cookieName ) {
			if ( $request->getCookie( $cookieName, '', '' ) !== '' ) {
				wfDebug( __METHOD__ . ": found $cookieName" );
				return true;
			}
		}
		wfDebug( __METHOD__ . ": no cache-varying cookies found" );
		return false;
	}

	/**
	 * Add an HTTP header that will have an influence on the cache
	 *
	 * @param string $header Header name
	 */
	public function addVaryHeader( $header ) {
		if ( !array_key_exists( $header, $this->mVaryHeader ) ) {
			$this->mVaryHeader[$header] = null;
		}
	}

	/**
	 * Return a Vary: header on which to vary caches. Based on the keys of $mVaryHeader,
	 * such as Accept-Encoding or Cookie
	 *
	 * @return string
	 */
	public function getVaryHeader() {
		// If we vary on cookies, let's make sure it's always included here too.
		if ( $this->getCacheVaryCookies() ) {
			$this->addVaryHeader( 'Cookie' );
		}

		foreach ( SessionManager::singleton()->getVaryHeaders() as $header => $_ ) {
			$this->addVaryHeader( $header );
		}
		return 'Vary: ' . implode( ', ', array_keys( $this->mVaryHeader ) );
	}

	/**
	 * Add an HTTP Link: header
	 *
	 * @param string $header Header value
	 */
	public function addLinkHeader( $header ) {
		$this->mLinkHeader[] = $header;
	}

	/**
	 * Return a Link: header. Based on the values of $mLinkHeader.
	 *
	 * @return string|false
	 */
	public function getLinkHeader() {
		if ( !$this->mLinkHeader ) {
			return false;
		}

		return 'Link: ' . implode( ',', $this->mLinkHeader );
	}

	/**
	 * T23672: Add Accept-Language to Vary header if there's no 'variant' parameter in GET.
	 *
	 * For example:
	 *   /w/index.php?title=Main_page will vary based on Accept-Language; but
	 *   /w/index.php?title=Main_page&variant=zh-cn will not.
	 */
	private function addAcceptLanguage() {
		$title = $this->getTitle();
		if ( !$title instanceof Title ) {
			return;
		}

		$languageConverter = MediaWikiServices::getInstance()->getLanguageConverterFactory()
			->getLanguageConverter( $title->getPageLanguage() );
		if ( !$this->getRequest()->getCheck( 'variant' ) && $languageConverter->hasVariants() ) {
			$this->addVaryHeader( 'Accept-Language' );
		}
	}

	/**
	 * Set the prevent-clickjacking flag.
	 *
	 * If true, will cause an X-Frame-Options header appropriate for
	 * edit pages to be sent. The header value is controlled by
	 * $wgEditPageFrameOptions.  This is the default for special
	 * pages. If you display a CSRF-protected form on an ordinary view
	 * page, then you need to call this function.
	 *
	 * Setting this flag to false will turn off frame-breaking.  This
	 * can be called from pages which do not contain any
	 * CSRF-protected HTML form.
	 *
	 * @param bool $enable If true, will cause an X-Frame-Options header
	 *  appropriate for edit pages to be sent.
	 *
	 * @since 1.38
	 */
	public function setPreventClickjacking( bool $enable ) {
		$this->mPreventClickjacking = $enable;
	}

	/**
	 * Get the prevent-clickjacking flag
	 *
	 * @since 1.24
	 * @return bool
	 */
	public function getPreventClickjacking() {
		return $this->mPreventClickjacking;
	}

	/**
	 * Get the X-Frame-Options header value (without the name part), or false
	 * if there isn't one. This is used by Skin to determine whether to enable
	 * JavaScript frame-breaking, for clients that don't support X-Frame-Options.
	 *
	 * @return string|false
	 */
	public function getFrameOptions() {
		$config = $this->getConfig();
		if ( $config->get( MainConfigNames::BreakFrames ) ) {
			return 'DENY';
		} elseif ( $this->mPreventClickjacking && $config->get( MainConfigNames::EditPageFrameOptions ) ) {
			return $config->get( MainConfigNames::EditPageFrameOptions );
		}
		return false;
	}

	private function getReportTo() {
		$config = $this->getConfig();

		$expiry = $config->get( MainConfigNames::ReportToExpiry );

		if ( !$expiry ) {
			return false;
		}

		$endpoints = $config->get( MainConfigNames::ReportToEndpoints );

		if ( !$endpoints ) {
			return false;
		}

		$output = [ 'max_age' => $expiry, 'endpoints' => [] ];

		foreach ( $endpoints as $endpoint ) {
			$output['endpoints'][] = [ 'url' => $endpoint ];
		}

		return json_encode( $output, JSON_UNESCAPED_SLASHES );
	}

	private function getFeaturePolicyReportOnly() {
		$config = $this->getConfig();

		$features = $config->get( MainConfigNames::FeaturePolicyReportOnly );
		return implode( ';', $features );
	}

	/**
	 * Send cache control HTTP headers
	 */
	public function sendCacheControl() {
		$response = $this->getRequest()->response();
		$config = $this->getConfig();

		$this->addVaryHeader( 'Cookie' );
		$this->addAcceptLanguage();

		# don't serve compressed data to clients who can't handle it
		# maintain different caches for logged-in users and non-logged in ones
		$response->header( $this->getVaryHeader() );

		if ( $this->mEnableClientCache ) {
			if ( !$config->get( MainConfigNames::UseCdn ) ) {
				$privateReason = 'config';
			} elseif ( $response->hasCookies() ) {
				$privateReason = 'set-cookies';
			// The client might use methods other than cookies to appear logged-in.
			// E.g. HTTP headers, or query parameter tokens, OAuth, etc.
			} elseif ( SessionManager::getGlobalSession()->isPersistent() ) {
				$privateReason = 'session';
			} elseif ( $this->isPrintable() ) {
				$privateReason = 'printable';
			} elseif ( $this->mCdnMaxage == 0 ) {
				$privateReason = 'no-maxage';
			} elseif ( $this->haveCacheVaryCookies() ) {
				$privateReason = 'cache-vary-cookies';
			} else {
				$privateReason = false;
			}

			if ( $privateReason === false ) {
				# We'll purge the proxy cache for anons explicitly, but require end user agents
				# to revalidate against the proxy on each visit.
				# IMPORTANT! The CDN needs to replace the Cache-Control header with
				# Cache-Control: s-maxage=0, must-revalidate, max-age=0
				wfDebug( __METHOD__ .
					": local proxy caching; {$this->mLastModified} **", 'private' );
				# start with a shorter timeout for initial testing
				# header( "Cache-Control: s-maxage=2678400, must-revalidate, max-age=0" );
				$response->header( "Cache-Control: " .
					"s-maxage={$this->mCdnMaxage}, must-revalidate, max-age=0" );
			} else {
				# We do want clients to cache if they can, but they *must* check for updates
				# on revisiting the page.
				wfDebug( __METHOD__ . ": private caching ($privateReason); {$this->mLastModified} **", 'private' );

				$response->header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT' );
				$response->header( "Cache-Control: private, must-revalidate, max-age=0" );
			}
			if ( $this->mLastModified ) {
				$response->header( "Last-Modified: {$this->mLastModified}" );
			}
		} else {
			wfDebug( __METHOD__ . ": no caching **", 'private' );

			# In general, the absence of a last modified header should be enough to prevent
			# the client from using its cache. We send a few other things just to make sure.
			$response->header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT' );
			$response->header( 'Cache-Control: no-cache, no-store, max-age=0, must-revalidate' );
		}
	}

	/**
	 * Transfer styles and JavaScript modules from skin.
	 *
	 * @param Skin $sk to load modules for
	 */
	public function loadSkinModules( $sk ) {
		foreach ( $sk->getDefaultModules() as $group => $modules ) {
			if ( $group === 'styles' ) {
				foreach ( $modules as $moduleMembers ) {
					$this->addModuleStyles( $moduleMembers );
				}
			} else {
				$this->addModules( $modules );
			}
		}
	}

	/**
	 * Finally, all the text has been munged and accumulated into
	 * the object, let's actually output it:
	 *
	 * @param bool $return Set to true to get the result as a string rather than sending it
	 * @return string|null
	 */
	public function output( $return = false ) {
		if ( $this->mDoNothing ) {
			return $return ? '' : null;
		}

		$response = $this->getRequest()->response();
		$config = $this->getConfig();

		if ( $this->mRedirect != '' ) {
			$services = MediaWikiServices::getInstance();
			// Modern standards don't require redirect URLs to be absolute, but make it so just in case.
			// Note that this doesn't actually guarantee an absolute URL: relative-path URLs are left intact.
			$this->mRedirect = (string)$services->getUrlUtils()->expand( $this->mRedirect, PROTO_CURRENT );

			$redirect = $this->mRedirect;
			$code = $this->mRedirectCode;
			$content = '';

			if ( $this->getHookRunner()->onBeforePageRedirect( $this, $redirect, $code ) ) {
				if ( $code == '301' || $code == '303' ) {
					if ( !$config->get( MainConfigNames::DebugRedirects ) ) {
						$response->statusHeader( (int)$code );
					}
					$this->mLastModified = wfTimestamp( TS_RFC2822 );
				}
				if ( $config->get( MainConfigNames::VaryOnXFP ) ) {
					$this->addVaryHeader( 'X-Forwarded-Proto' );
				}
				$this->sendCacheControl();

				$response->header( 'Content-Type: text/html; charset=UTF-8' );
				if ( $config->get( MainConfigNames::DebugRedirects ) ) {
					$url = htmlspecialchars( $redirect );
					$content = "<!DOCTYPE html>\n<html>\n<head>\n"
						. "<title>Redirect</title>\n</head>\n<body>\n"
						. "<p>Location: <a href=\"$url\">$url</a></p>\n"
						. "</body>\n</html>\n";

					if ( !$return ) {
						print $content;
					}

				} else {
					$response->header( 'Location: ' . $redirect );
				}
			}

			return $return ? $content : null;
		} elseif ( $this->mStatusCode ) {
			$response->statusHeader( $this->mStatusCode );
		}

		# Buffer output; final headers may depend on later processing
		ob_start();

		$response->header( 'Content-type: ' . $config->get( MainConfigNames::MimeType ) . '; charset=UTF-8' );
		$response->header( 'Content-language: ' .
			MediaWikiServices::getInstance()->getContentLanguage()->getHtmlCode() );

		$linkHeader = $this->getLinkHeader();
		if ( $linkHeader ) {
			$response->header( $linkHeader );
		}

		// Prevent framing, if requested
		$frameOptions = $this->getFrameOptions();
		if ( $frameOptions ) {
			$response->header( "X-Frame-Options: $frameOptions" );
		}

		// Get the Origin-Trial header values. This is used to enable Chrome Origin
		// Trials: https://github.com/GoogleChrome/OriginTrials
		$originTrials = $config->get( MainConfigNames::OriginTrials );
		foreach ( $originTrials as $originTrial ) {
			$response->header( "Origin-Trial: $originTrial", false );
		}

		$reportTo = $this->getReportTo();
		if ( $reportTo ) {
			$response->header( "Report-To: $reportTo" );
		}

		$featurePolicyReportOnly = $this->getFeaturePolicyReportOnly();
		if ( $featurePolicyReportOnly ) {
			$response->header( "Feature-Policy-Report-Only: $featurePolicyReportOnly" );
		}

		if ( $this->mArticleBodyOnly ) {
			if ( $this->cspOutputMode === self::CSP_HEADERS ) {
				$this->CSP->sendHeaders();
			}
			echo $this->mBodytext;
		} else {
			// Enable safe mode if requested (T152169)
			if ( $this->getRequest()->getBool( 'safemode' ) ) {
				$this->disallowUserJs();
			}

			$sk = $this->getSkin();
			$this->loadSkinModules( $sk );

			MWDebug::addModules( $this );

			// Hook that allows last minute changes to the output page, e.g.
			// adding of CSS or Javascript by extensions, adding CSP sources.
			$this->getHookRunner()->onBeforePageDisplay( $this, $sk );

			if ( $this->cspOutputMode === self::CSP_HEADERS ) {
				$this->CSP->sendHeaders();
			}

			try {
				$sk->outputPage();
			} catch ( Exception $e ) {
				ob_end_clean(); // bug T129657
				throw $e;
			}
		}

		try {
			// This hook allows last minute changes to final overall output by modifying output buffer
			$this->getHookRunner()->onAfterFinalPageOutput( $this );
		} catch ( Exception $e ) {
			ob_end_clean(); // bug T129657
			throw $e;
		}

		$this->sendCacheControl();

		if ( $return ) {
			return ob_get_clean();
		} else {
			ob_end_flush();
			return null;
		}
	}

	/**
	 * Prepare this object to display an error page; disable caching and
	 * indexing, clear the current text and redirect, set the page's title
	 * and optionally a custom HTML title (content of the "<title>" tag).
	 *
	 * @param string|Message|null $pageTitle Will be passed directly to setPageTitle()
	 * @param string|Message|false $htmlTitle Will be passed directly to setHTMLTitle();
	 *                   optional, if not passed the "<title>" attribute will be
	 *                   based on $pageTitle
	 * @note Explicitly passing $pageTitle or $htmlTitle has been deprecated
	 *   since 1.41; use ::setPageTitleMsg() and ::setHTMLTitle() instead.
	 */
	public function prepareErrorPage( $pageTitle = null, $htmlTitle = false ) {
		if ( $pageTitle !== null || $htmlTitle !== false ) {
			wfDeprecated( __METHOD__ . ' with explicit arguments', '1.41' );
			if ( $pageTitle !== null ) {
				$this->setPageTitle( $pageTitle );
			}
			if ( $htmlTitle !== false ) {
				$this->setHTMLTitle( $htmlTitle );
			}
		}
		$this->setRobotPolicy( 'noindex,nofollow' );
		$this->setArticleRelated( false );
		$this->disableClientCache();
		$this->mRedirect = '';
		$this->clearSubtitle();
		$this->clearHTML();
	}

	/**
	 * Output a standard error page
	 *
	 * showErrorPage( 'titlemsg', 'pagetextmsg' );
	 * showErrorPage( 'titlemsg', 'pagetextmsg', [ 'param1', 'param2' ] );
	 * showErrorPage( 'titlemsg', $messageObject );
	 * showErrorPage( $titleMessageObject, $messageObject );
	 *
	 * @param string|Message $title Message key (string) for page title, or a Message object
	 * @param string|Message $msg Message key (string) for page text, or a Message object
	 * @param array $params Message parameters; ignored if $msg is a Message object
	 * @param PageReference|LinkTarget|string|null $returnto Page to show a return link to;
	 *   defaults to the 'returnto' URL parameter
	 * @param string|null $returntoquery Query string for the return to link;
	 *   defaults to the 'returntoquery' URL parameter
	 */
	public function showErrorPage(
		$title, $msg, $params = [], $returnto = null, $returntoquery = null
	) {
		if ( !$title instanceof Message ) {
			$title = $this->msg( $title );
		}

		$this->prepareErrorPage();
		$this->setPageTitleMsg( $title );

		if ( $msg instanceof Message ) {
			if ( $params !== [] ) {
				trigger_error( 'Argument ignored: $params. The message parameters argument '
					. 'is discarded when the $msg argument is a Message object instead of '
					. 'a string.', E_USER_NOTICE );
			}
			$this->addHTML( $msg->parseAsBlock() );
		} else {
			$this->addWikiMsgArray( $msg, $params );
		}

		$this->returnToMain( null, $returnto, $returntoquery );
	}

	/**
	 * Output a standard permission error page
	 *
	 * @param array $errors Error message keys or [key, param...] arrays
	 * @param string|null $action Action that was denied or null if unknown
	 */
	public function showPermissionsErrorPage( array $errors, $action = null ) {
		$services = MediaWikiServices::getInstance();
		$groupPermissionsLookup = $services->getGroupPermissionsLookup();
		foreach ( $errors as $key => $error ) {
			$errors[$key] = (array)$error;
		}

		// For some actions (read, edit, create and upload), display a "login to do this action"
		// error if all of the following conditions are met:
		// 1. the user is not logged in as a named user, and so cannot be added to groups
		// 2. the only error is insufficient permissions (i.e. no block or something else)
		// 3. the error can be avoided simply by logging in

		if ( in_array( $action, [ 'read', 'edit', 'createpage', 'createtalk', 'upload' ] )
			&& !$this->getUser()->isNamed() && count( $errors ) == 1 && isset( $errors[0][0] )
			&& ( $errors[0][0] == 'badaccess-groups' || $errors[0][0] == 'badaccess-group0' )
			&& ( $groupPermissionsLookup->groupHasPermission( 'user', $action )
				|| $groupPermissionsLookup->groupHasPermission( 'autoconfirmed', $action ) )
		) {
			$displayReturnto = null;

			# Due to T34276, if a user does not have read permissions,
			# $this->getTitle() will just give Special:Badtitle, which is
			# not especially useful as a returnto parameter. Use the title
			# from the request instead, if there was one.
			$request = $this->getRequest();
			$returnto = Title::newFromText( $request->getText( 'title' ) );
			if ( $action == 'edit' ) {
				$msg = 'whitelistedittext';
				$displayReturnto = $returnto;
			} elseif ( $action == 'createpage' || $action == 'createtalk' ) {
				$msg = 'nocreatetext';
			} elseif ( $action == 'upload' ) {
				$msg = 'uploadnologintext';
			} else { # Read
				$msg = 'loginreqpagetext';
				$displayReturnto = Title::newMainPage();
			}

			$query = [];

			if ( $returnto ) {
				$query['returnto'] = $returnto->getPrefixedText();

				if ( !$request->wasPosted() ) {
					$returntoquery = $request->getValues();
					unset( $returntoquery['title'] );
					unset( $returntoquery['returnto'] );
					unset( $returntoquery['returntoquery'] );
					$query['returntoquery'] = wfArrayToCgi( $returntoquery );
				}
			}

			$title = SpecialPage::getTitleFor( 'Userlogin' );
			$linkRenderer = $services->getLinkRenderer();
			$loginUrl = $title->getLinkURL( $query, false, PROTO_RELATIVE );
			$loginLink = $linkRenderer->makeKnownLink(
				$title,
				$this->msg( 'loginreqlink' )->text(),
				[],
				$query
			);

			$this->prepareErrorPage();
			$this->setPageTitleMsg( $this->msg( 'loginreqtitle' ) );
			$this->addHTML( $this->msg( $msg )->rawParams( $loginLink )->params( $loginUrl )->parse() );

			# Don't return to a page the user can't read otherwise
			# we'll end up in a pointless loop
			if ( $displayReturnto && $this->getAuthority()->probablyCan( 'read', $displayReturnto ) ) {
				$this->returnToMain( null, $displayReturnto );
			}
		} else {
			$this->prepareErrorPage();
			$this->setPageTitleMsg( $this->msg( 'permissionserrors' ) );
			$this->addWikiTextAsInterface( $this->formatPermissionsErrorMessage( $errors, $action ) );
		}
	}

	/**
	 * Display an error page indicating that a given version of MediaWiki is
	 * required to use it
	 *
	 * @param mixed $version The version of MediaWiki needed to use the page
	 */
	public function versionRequired( $version ) {
		$this->prepareErrorPage();
		$this->setPageTitleMsg(
			$this->msg( 'versionrequired' )->plaintextParams( $version )
		);

		$this->addWikiMsg( 'versionrequiredtext', $version );
		$this->returnToMain();
	}

	/**
	 * Format permission $status obtained from Authority for display.
	 *
	 * @param PermissionStatus $status
	 * @param string|null $action that was denied or null if unknown
	 * @return string
	 */
	public function formatPermissionStatus( PermissionStatus $status, string $action = null ): string {
		if ( $status->isGood() ) {
			return '';
		}
		return $this->formatPermissionsErrorMessage( $status->toLegacyErrorArray(), $action );
	}

	/**
	 * Format a list of error messages
	 *
	 * @deprecated since 1.36. Use ::formatPermissionStatus instead
	 * @param array $errors Array of arrays returned by PermissionManager::getPermissionErrors
	 * @phan-param non-empty-array[] $errors
	 * @param string|null $action Action that was denied or null if unknown
	 * @return string The wikitext error-messages, formatted into a list.
	 */
	public function formatPermissionsErrorMessage( array $errors, $action = null ) {
		if ( $action == null ) {
			$text = $this->msg( 'permissionserrorstext', count( $errors ) )->plain() . "\n\n";
		} else {
			$action_desc = $this->msg( "action-$action" )->plain();
			$text = $this->msg(
				'permissionserrorstext-withaction',
				count( $errors ),
				$action_desc
			)->plain() . "\n\n";
		}

		if ( count( $errors ) > 1 ) {
			$text .= '<ul class="permissions-errors">' . "\n";

			foreach ( $errors as $error ) {
				$text .= '<li>';
				$text .= $this->msg( ...$error )->plain();
				$text .= "</li>\n";
			}
			$text .= '</ul>';
		} else {
			$text .= "<div class=\"permissions-errors\">\n" .
					// @phan-suppress-next-line PhanParamTooFewUnpack Elements of $errors already annotated as non-empty
					$this->msg( ...reset( $errors ) )->plain() .
					"\n</div>";
		}

		return $text;
	}

	/**
	 * Show a warning about replica DB lag
	 *
	 * If the lag is higher than $wgDatabaseReplicaLagCritical seconds,
	 * then the warning is a bit more obvious. If the lag is
	 * lower than $wgDatabaseReplicaLagWarning, then no warning is shown.
	 *
	 * @param int $lag Replica lag
	 */
	public function showLagWarning( $lag ) {
		$config = $this->getConfig();
		if ( $lag >= $config->get( MainConfigNames::DatabaseReplicaLagWarning ) ) {
			$lag = floor( $lag ); // floor to avoid nano seconds to display
			$message = $lag < $config->get( MainConfigNames::DatabaseReplicaLagCritical )
				? 'lag-warn-normal'
				: 'lag-warn-high';
			// For grep: mw-lag-warn-normal, mw-lag-warn-high
			$wrap = Html::rawElement( 'div', [ 'class' => "mw-{$message}" ], "\n$1\n" );
			$this->wrapWikiMsg( "$wrap\n", [ $message, $this->getLanguage()->formatNum( $lag ) ] );
		}
	}

	/**
	 * Output an error page
	 *
	 * @note FatalError exception class provides an alternative.
	 * @param string $message Error to output. Must be escaped for HTML.
	 */
	public function showFatalError( $message ) {
		$this->prepareErrorPage();
		$this->setPageTitleMsg( $this->msg( 'internalerror' ) );

		$this->addHTML( $message );
	}

	/**
	 * Add a "return to" link pointing to a specified title
	 *
	 * @param LinkTarget $title Title to link
	 * @param array $query Query string parameters
	 * @param string|null $text Text of the link (input is not escaped)
	 * @param array $options Options array to pass to Linker
	 */
	public function addReturnTo( $title, array $query = [], $text = null, $options = [] ) {
		$linkRenderer = MediaWikiServices::getInstance()
			->getLinkRendererFactory()->createFromLegacyOptions( $options );
		$link = $this->msg( 'returnto' )->rawParams(
			$linkRenderer->makeLink( $title, $text, [], $query ) )->escaped();
		$this->addHTML( "<p id=\"mw-returnto\">{$link}</p>\n" );
	}

	/**
	 * Add a "return to" link pointing to a specified title,
	 * or the title indicated in the request, or else the main page
	 *
	 * @param mixed|null $unused
	 * @param PageReference|LinkTarget|string|null $returnto Page to return to
	 * @param string|null $returntoquery Query string for the return to link
	 */
	public function returnToMain( $unused = null, $returnto = null, $returntoquery = null ) {
		$returnto ??= $this->getRequest()->getText( 'returnto' );

		$returntoquery ??= $this->getRequest()->getText( 'returntoquery' );

		if ( $returnto === '' ) {
			$returnto = Title::newMainPage();
		}

		if ( is_object( $returnto ) ) {
			$linkTarget = TitleValue::castPageToLinkTarget( $returnto );
		} else {
			$linkTarget = Title::newFromText( $returnto );
		}

		// We don't want people to return to external interwiki. That
		// might potentially be used as part of a phishing scheme
		if ( !is_object( $linkTarget ) || $linkTarget->isExternal() ) {
			$linkTarget = Title::newMainPage();
		}

		$this->addReturnTo( $linkTarget, wfCgiToArray( $returntoquery ) );
	}

	/**
	 * Output a standard "wait for takeover" warning
	 *
	 * This is useful for extensions which are hooking an action and
	 * suppressing its normal output so it can be taken over with JS.
	 *
	 * showPendingTakeover( 'url', 'pagetextmsg' );
	 * showPendingTakeover( 'url', 'pagetextmsg', [ 'param1', 'param2' ] );
	 * showPendingTakeover( 'url', $messageObject );
	 *
	 * @param string $fallbackUrl URL to redirect to if the user doesn't have JavaScript
	 *  or ResourceLoader available; this should ideally be to a page that provides similar
	 *  functionality without requiring JavaScript
	 * @param string|Message $msg Message key (string) for page text, or a Message object
	 * @param mixed ...$params Message parameters; ignored if $msg is a Message object
	 */
	public function showPendingTakeover(
		$fallbackUrl, $msg, ...$params
	) {
		if ( $msg instanceof Message ) {
			if ( $params !== [] ) {
				trigger_error( 'Argument ignored: $params. The message parameters argument '
					. 'is discarded when the $msg argument is a Message object instead of '
					. 'a string.', E_USER_NOTICE );
			}
			$this->addHTML( $msg->parseAsBlock() );
		} else {
			$this->addHTML( $this->msg( $msg, ...$params )->parseAsBlock() );
		}

		// Redirect if the user has no JS (<noscript>)
		$escapedUrl = htmlspecialchars( $fallbackUrl );
		$this->addHeadItem(
			'mw-noscript-fallback',
			// https://html.spec.whatwg.org/#attr-meta-http-equiv-refresh
			// means that if $fallbackUrl contains unencoded quotation marks
			// then this will behave confusingly, but shouldn't break the page
			"<noscript><meta http-equiv=\"refresh\" content=\"0; url=$escapedUrl\"></noscript>"
		);
		// Redirect if the user has no ResourceLoader
		$this->addScript( Html::inlineScript(
			"(window.NORLQ=window.NORLQ||[]).push(" .
				"function(){" .
					"location.href=" . json_encode( $fallbackUrl ) . ";" .
				"}" .
			");"
		) );
	}

	private function getRlClientContext() {
		if ( !$this->rlClientContext ) {
			$query = ResourceLoader::makeLoaderQuery(
				[], // modules; not relevant
				$this->getLanguage()->getCode(),
				$this->getSkin()->getSkinName(),
				$this->getUser()->isRegistered() ? $this->getUser()->getName() : null,
				null, // version; not relevant
				ResourceLoader::inDebugMode(),
				null, // only; not relevant
				$this->isPrintable()
			);
			$this->rlClientContext = new RL\Context(
				$this->getResourceLoader(),
				new FauxRequest( $query )
			);
			if ( $this->contentOverrideCallbacks ) {
				$this->rlClientContext = new RL\DerivativeContext( $this->rlClientContext );
				$this->rlClientContext->setContentOverrideCallback( function ( $title ) {
					foreach ( $this->contentOverrideCallbacks as $callback ) {
						$content = $callback( $title );
						if ( $content !== null ) {
							$text = ( $content instanceof TextContent ) ? $content->getText() : '';
							if ( strpos( $text, '</script>' ) !== false ) {
								// Proactively replace this so that we can display a message
								// to the user, instead of letting it go to Html::inlineScript(),
								// where it would be considered a server-side issue.
								$content = new JavaScriptContent(
									Html::encodeJsCall( 'mw.log.error', [
										"Cannot preview $title due to script-closing tag."
									] )
								);
							}
							return $content;
						}
					}
					return null;
				} );
			}
		}
		return $this->rlClientContext;
	}

	/**
	 * Call this to freeze the module queue and JS config and create a formatter.
	 *
	 * Depending on the Skin, this may get lazy-initialised in either headElement() or
	 * getBottomScripts(). See SkinTemplate::prepareQuickTemplate(). Calling this too early may
	 * cause unexpected side-effects since disallowUserJs() may be called at any time to change
	 * the module filters retroactively. Skins and extension hooks may also add modules until very
	 * late in the request lifecycle.
	 *
	 * @return RL\ClientHtml
	 */
	public function getRlClient() {
		if ( !$this->rlClient ) {
			$context = $this->getRlClientContext();
			$rl = $this->getResourceLoader();
			$this->addModules( [
				'user',
				'user.options',
			] );
			$this->addModuleStyles( [
				'site.styles',
				'noscript',
				'user.styles',
			] );

			// Prepare exempt modules for buildExemptModules()
			$exemptGroups = [
				RL\Module::GROUP_SITE => [],
				RL\Module::GROUP_NOSCRIPT => [],
				RL\Module::GROUP_PRIVATE => [],
				RL\Module::GROUP_USER => []
			];
			$exemptStates = [];
			$moduleStyles = $this->getModuleStyles( /*filter*/ true );

			// Preload getTitleInfo for isKnownEmpty calls below and in RL\ClientHtml
			// Separate user-specific batch for improved cache-hit ratio.
			$userBatch = [ 'user.styles', 'user' ];
			$siteBatch = array_diff( $moduleStyles, $userBatch );
			RL\WikiModule::preloadTitleInfo( $context, $siteBatch );
			RL\WikiModule::preloadTitleInfo( $context, $userBatch );

			// Filter out modules handled by buildExemptModules()
			$moduleStyles = array_filter( $moduleStyles,
				static function ( $name ) use ( $rl, $context, &$exemptGroups, &$exemptStates ) {
					$module = $rl->getModule( $name );
					if ( $module ) {
						$group = $module->getGroup();
						if ( $group !== null && isset( $exemptGroups[$group] ) ) {
							// The `noscript` module is excluded from the client
							// side registry, no need to set its state either.
							// But we still output it. See T291735
							if ( $group !== RL\Module::GROUP_NOSCRIPT ) {
								$exemptStates[$name] = 'ready';
							}
							if ( !$module->isKnownEmpty( $context ) ) {
								// E.g. Don't output empty <styles>
								$exemptGroups[$group][] = $name;
							}
							return false;
						}
					}
					return true;
				}
			);
			$this->rlExemptStyleModules = $exemptGroups;

			$config = $this->getConfig();
			// Client preferences are controlled by the skin and specific to unregistered
			// users. See mw.user.clientPrefs for details on how this works and how to
			// handle registered users.
			$clientPrefEnabled = (
				$this->getSkin()->getOptions()['clientPrefEnabled'] &&
				!$this->getUser()->isNamed()
			);
			$clientPrefCookiePrefix = $config->get( MainConfigNames::CookiePrefix );

			$rlClient = new RL\ClientHtml( $context, [
				'target' => $this->getTarget(),
				// When 'safemode', disallowUserJs(), or reduceAllowedModules() is used
				// to only restrict modules to ORIGIN_CORE (ie. disallow ORIGIN_USER), the list of
				// modules enqueued for loading on this page is filtered to just those.
				// However, to make sure we also apply the restriction to dynamic dependencies and
				// lazy-loaded modules at run-time on the client-side, pass 'safemode' down to the
				// StartupModule so that the client-side registry will not contain any restricted
				// modules either. (T152169, T185303)
				'safemode' => ( $this->getAllowedModules( RL\Module::TYPE_COMBINED )
					<= RL\Module::ORIGIN_CORE_INDIVIDUAL
				) ? '1' : null,
				'clientPrefEnabled' => $clientPrefEnabled,
				'clientPrefCookiePrefix' => $clientPrefCookiePrefix,
			] );
			$rlClient->setConfig( $this->getJSVars( self::JS_VAR_EARLY ) );
			$rlClient->setModules( $this->getModules( /*filter*/ true ) );
			$rlClient->setModuleStyles( $moduleStyles );
			$rlClient->setExemptStates( $exemptStates );
			$this->rlClient = $rlClient;
		}
		return $this->rlClient;
	}

	/**
	 * @param Skin $sk The given Skin
	 * @param bool $includeStyle Unused
	 * @return string The doctype, opening "<html>", and head element.
	 */
	public function headElement( Skin $sk, $includeStyle = true ) {
		$config = $this->getConfig();
		$userdir = $this->getLanguage()->getDir();
		$services = MediaWikiServices::getInstance();
		$sitedir = $services->getContentLanguage()->getDir();

		$pieces = [];
		$htmlAttribs = Sanitizer::mergeAttributes( Sanitizer::mergeAttributes(
			$this->getRlClient()->getDocumentAttributes(),
			$sk->getHtmlElementAttributes()
		), [ 'class' => implode( ' ', $this->mAdditionalHtmlClasses ) ] );
		$pieces[] = Html::htmlHeader( $htmlAttribs );
		$pieces[] = Html::openElement( 'head' );

		if ( $this->getHTMLTitle() == '' ) {
			$this->setHTMLTitle( $this->msg( 'pagetitle', $this->getPageTitle() )->inContentLanguage() );
		}

		if ( !Html::isXmlMimeType( $config->get( MainConfigNames::MimeType ) ) ) {
			// Add <meta charset="UTF-8">
			// This should be before <title> since it defines the charset used by
			// text including the text inside <title>.
			// The spec recommends defining XHTML5's charset using the XML declaration
			// instead of meta.
			// Our XML declaration is output by Html::htmlHeader.
			// https://html.spec.whatwg.org/multipage/semantics.html#attr-meta-http-equiv-content-type
			// https://html.spec.whatwg.org/multipage/semantics.html#charset
			$pieces[] = Html::element( 'meta', [ 'charset' => 'UTF-8' ] );
		}

		$pieces[] = Html::element( 'title', [], $this->getHTMLTitle() );
		$pieces[] = $this->getRlClient()->getHeadHtml( $htmlAttribs['class'] ?? null );
		$pieces[] = $this->buildExemptModules();
		$pieces = array_merge( $pieces, array_values( $this->getHeadLinksArray() ) );
		$pieces = array_merge( $pieces, array_values( $this->mHeadItems ) );

		$pieces[] = Html::closeElement( 'head' );

		$skinOptions = $sk->getOptions();
		$bodyClasses = array_merge( $this->mAdditionalBodyClasses, $skinOptions['bodyClasses'] );
		$bodyClasses[] = 'mediawiki';

		# Classes for LTR/RTL directionality support
		$bodyClasses[] = $userdir;
		$bodyClasses[] = "sitedir-$sitedir";

		// See Article:showDiffPage for class to support article diff styling

		$underline = $services->getUserOptionsLookup()->getOption( $this->getUser(), 'underline' );
		if ( $underline < 2 ) {
			// The following classes can be used here:
			// * mw-underline-always
			// * mw-underline-never
			$bodyClasses[] = 'mw-underline-' . ( $underline ? 'always' : 'never' );
		}

		// Parser feature migration class
		// The idea is that this will eventually be removed, after the wikitext
		// which requires it is cleaned up.
		$bodyClasses[] = 'mw-hide-empty-elt';

		$bodyClasses[] = $sk->getPageClasses( $this->getTitle() );
		$bodyClasses[] = 'skin-' . Sanitizer::escapeClass( $sk->getSkinName() );
		$bodyClasses[] =
			'action-' . Sanitizer::escapeClass( $this->getContext()->getActionName() );

		if ( $sk->isResponsive() ) {
			$bodyClasses[] = 'skin--responsive';
		}

		$bodyAttrs = [];
		// While the implode() is not strictly needed, it's used for backwards compatibility
		// (this used to be built as a string and hooks likely still expect that).
		$bodyAttrs['class'] = implode( ' ', $bodyClasses );

		// Allow skins and extensions to add body attributes they need
		// Get ones from deprecated method
		if ( method_exists( $sk, 'addToBodyAttributes' ) ) {
			/** @phan-suppress-next-line PhanUndeclaredMethod */
			$sk->addToBodyAttributes( $this, $bodyAttrs );
			wfDeprecated( 'Skin::addToBodyAttributes method to add body attributes', '1.35' );
		}

		// Then run the hook, the recommended way of adding body attributes now
		$this->getHookRunner()->onOutputPageBodyAttributes( $this, $sk, $bodyAttrs );

		$pieces[] = Html::openElement( 'body' ) . "\n";

		return self::combineWrappedStrings( $pieces );
	}

	/**
	 * Get a ResourceLoader object associated with this OutputPage
	 *
	 * @return ResourceLoader
	 */
	public function getResourceLoader() {
		if ( $this->mResourceLoader === null ) {
			// Lazy-initialise as needed
			$this->mResourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		}
		return $this->mResourceLoader;
	}

	/**
	 * Explicitly load or embed modules on a page.
	 *
	 * @param array|string $modules One or more module names
	 * @param string $only RL\Module TYPE_ class constant
	 * @param array $extraQuery [optional] Array with extra query parameters for the request
	 * @return string|WrappedStringList HTML
	 */
	public function makeResourceLoaderLink( $modules, $only, array $extraQuery = [] ) {
		// Apply 'origin' filters
		$modules = $this->filterModules( (array)$modules, null, $only );

		return RL\ClientHtml::makeLoad(
			$this->getRlClientContext(),
			$modules,
			$only,
			$extraQuery
		);
	}

	/**
	 * Combine WrappedString chunks and filter out empty ones
	 *
	 * @param array $chunks
	 * @return string|WrappedStringList HTML
	 */
	protected static function combineWrappedStrings( array $chunks ) {
		// Filter out empty values
		$chunks = array_filter( $chunks, 'strlen' );
		return WrappedString::join( "\n", $chunks );
	}

	/**
	 * JS stuff to put at the bottom of the `<body>`.
	 * These are legacy scripts ($this->mScripts), and user JS.
	 *
	 * @return string|WrappedStringList HTML
	 */
	public function getBottomScripts() {
		// Keep the hook appendage separate to preserve WrappedString objects.
		// This enables to merge them where possible.
		$extraHtml = '';
		$this->getHookRunner()->onSkinAfterBottomScripts( $this->getSkin(), $extraHtml );

		$chunks = [];
		$chunks[] = $this->getRlClient()->getBodyHtml();

		// Legacy non-ResourceLoader scripts
		$chunks[] = $this->mScripts;

		// Keep hostname and backend time as the first variables for quick view-source access.
		// This other variables will form a very long inline blob.
		$vars = [];
		if ( $this->getConfig()->get( MainConfigNames::ShowHostnames ) ) {
			$vars['wgHostname'] = wfHostname();
		}
		$elapsed = $this->getRequest()->getElapsedTime();
		// seconds to milliseconds
		$vars['wgBackendResponseTime'] = round( $elapsed * 1000 );

		$vars += $this->getJSVars( self::JS_VAR_LATE );
		if ( $this->limitReportJSData ) {
			$vars['wgPageParseReport'] = $this->limitReportJSData;
		}

		$rlContext = $this->getRlClientContext();
		$chunks[] = ResourceLoader::makeInlineScript(
			'mw.config.set(' . $rlContext->encodeJson( $vars ) . ');'
		);

		$chunks = [ self::combineWrappedStrings( $chunks ) ];
		if ( $extraHtml !== '' ) {
			$chunks[] = $extraHtml;
		}

		return WrappedString::join( "\n", $chunks );
	}

	/**
	 * Get the javascript config vars to include on this page
	 *
	 * @return array Array of javascript config vars
	 * @since 1.23
	 */
	public function getJsConfigVars() {
		return $this->mJsConfigVars;
	}

	/**
	 * Add one or more variables to be set in mw.config in JavaScript
	 *
	 * @param string|array $keys Key or array of key/value pairs
	 * @param mixed|null $value [optional] Value of the configuration variable
	 */
	public function addJsConfigVars( $keys, $value = null ) {
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key => $value ) {
				$this->mJsConfigVars[$key] = $value;
			}
			return;
		}

		$this->mJsConfigVars[$keys] = $value;
	}

	/**
	 * Get an array containing the variables to be set in mw.config in JavaScript.
	 *
	 * Do not add things here which can be evaluated in RL\StartUpModule,
	 * in other words, page-independent/site-wide variables (without state).
	 * These would add a blocking HTML cost to page rendering time, and require waiting for
	 * HTTP caches to expire before configuration changes take effect everywhere.
	 *
	 * By default, these are loaded in the HTML head and block page rendering.
	 * Config variable names can be set in CORE_LATE_JS_CONFIG_VAR_NAMES, or
	 * for extensions via the 'LateJSConfigVarNames' attribute, to opt-in to
	 * being sent from the end of the HTML body instead, to improve page load time.
	 * In JavaScript, late variables should be accessed via mw.hook('wikipage.content').
	 *
	 * @param int|null $flag Return only the specified kind of variables: self::JS_VAR_EARLY or self::JS_VAR_LATE.
	 *   For internal use only.
	 * @return array
	 */
	public function getJSVars( ?int $flag = null ) {
		$curRevisionId = 0;
		$articleId = 0;
		$canonicalSpecialPageName = false; # T23115
		$services = MediaWikiServices::getInstance();

		$title = $this->getTitle();
		$ns = $title->getNamespace();
		$nsInfo = $services->getNamespaceInfo();
		$canonicalNamespace = $nsInfo->exists( $ns )
			? $nsInfo->getCanonicalName( $ns )
			: $title->getNsText();

		$sk = $this->getSkin();
		// Get the relevant title so that AJAX features can use the correct page name
		// when making API requests from certain special pages (T36972).
		$relevantTitle = $sk->getRelevantTitle();

		if ( $ns === NS_SPECIAL ) {
			[ $canonicalSpecialPageName, /*...*/ ] =
				$services->getSpecialPageFactory()->
					resolveAlias( $title->getDBkey() );
		} elseif ( $this->canUseWikiPage() ) {
			$wikiPage = $this->getWikiPage();
			// If we already know that the latest revision ID is the same as the revision ID being viewed,
			// avoid fetching it again, as it may give inconsistent results (T339164).
			if ( $this->isRevisionCurrent() && $this->getRevisionId() ) {
				$curRevisionId = $this->getRevisionId();
			} else {
				$curRevisionId = $wikiPage->getLatest();
			}
			$articleId = $wikiPage->getId();
		}

		// ParserOutput informs HTML/CSS via lang/dir attributes.
		// We inform JavaScript via mw.config from here.
		$lang = $this->getContentLangForJS();

		// Pre-process information
		$separatorTransTable = $lang->separatorTransformTable();
		$separatorTransTable = $separatorTransTable ?: [];
		$compactSeparatorTransTable = [
			implode( "\t", array_keys( $separatorTransTable ) ),
			implode( "\t", $separatorTransTable ),
		];
		$digitTransTable = $lang->digitTransformTable();
		$digitTransTable = $digitTransTable ?: [];
		$compactDigitTransTable = [
			implode( "\t", array_keys( $digitTransTable ) ),
			implode( "\t", $digitTransTable ),
		];

		$user = $this->getUser();

		// Internal variables for MediaWiki core
		$vars = [];

		// Start of supported and stable config vars (for use by extensions/gadgets).
		$vars += [
			'wgNamespaceNumber' => $title->getNamespace(),
			'wgTitle' => $title->getText(),
			'wgCurRevisionId' => $curRevisionId,
			'wgRevisionId' => (int)$this->getRevisionId(),
			'wgAction' => $this->getContext()->getActionName(),
			'wgUserName' => $user->isAnon() ? null : $user->getName(),
		];
		if ( $user->isRegistered() ) {
			$vars['wgUserId'] = $user->getId();
			$vars['wgUserIsTemp'] = $user->isTemp();
			$vars['wgUserEditCount'] = $user->getEditCount();
			$userReg = $user->getRegistration();
			$vars['wgUserRegistration'] = $userReg ? (int)wfTimestamp( TS_UNIX, $userReg ) * 1000 : null;
			$userFirstReg = $services->getUserRegistrationLookup()->getFirstRegistration( $user );
			$vars['wgUserFirstRegistration'] = $userFirstReg ? (int)wfTimestamp( TS_UNIX, $userFirstReg ) * 1000 : null;
			// Get the revision ID of the oldest new message on the user's talk
			// page. This can be used for constructing new message alerts on
			// the client side.
			$userNewMsgRevId = $this->getLastSeenUserTalkRevId();
			// Only occupy precious space in the <head> when it is non-null (T53640)
			// mw.config.get returns null by default.
			if ( $userNewMsgRevId ) {
				$vars['wgUserNewMsgRevisionId'] = $userNewMsgRevId;
			}
		} else {
			$tempUserCreator = $services->getTempUserCreator();
			if ( $tempUserCreator->isEnabled() ) {
				// For logged-out users only (without a temporary account): get the user name that will
				// be used for their temporary account, if it has already been acquired.
				// This may be used in previews.
				$session = $this->getRequest()->getSession();
				$vars['wgTempUserName'] = $tempUserCreator->getStashedName( $session );
			}
		}
		$languageConverter = $services->getLanguageConverterFactory()
			->getLanguageConverter( $title->getPageLanguage() );
		if ( $languageConverter->hasVariants() ) {
			$vars['wgUserVariant'] = $languageConverter->getPreferredVariant();
		}
		// Same test as SkinTemplate
		$vars['wgIsEditable'] = $this->getAuthority()->probablyCan( 'edit', $title );
		$restrictionStore = $services->getRestrictionStore();

		if ( $title->isMainPage() ) {
			$vars['wgIsMainPage'] = true;
		}

		$relevantUser = $sk->getRelevantUser();
		if ( $relevantUser ) {
			$vars['wgRelevantUserName'] = $relevantUser->getName();
		}
		// End of stable config vars

		$titleFormatter = $services->getTitleFormatter();

		if ( $this->mRedirectedFrom ) {
			// @internal For skin JS
			$vars['wgRedirectedFrom'] = $titleFormatter->getPrefixedDBkey( $this->mRedirectedFrom );
		}

		// Allow extensions to add their custom variables to the mw.config map.
		// Use the 'ResourceLoaderGetConfigVars' hook if the variable is not
		// page-dependent but site-wide (without state).
		// Alternatively, you may want to use OutputPage->addJsConfigVars() instead.
		$this->getHookRunner()->onMakeGlobalVariablesScript( $vars, $this );

		// Merge in variables from addJsConfigVars last
		$vars = array_merge( $vars, $this->getJsConfigVars() );

		// Return only early or late vars if requested
		if ( $flag !== null ) {
			$lateVarNames =
				array_fill_keys( self::CORE_LATE_JS_CONFIG_VAR_NAMES, true ) +
				array_fill_keys( ExtensionRegistry::getInstance()->getAttribute( 'LateJSConfigVarNames' ), true );
			foreach ( $vars as $name => $_ ) {
				// If the variable's late flag doesn't match the requested late flag, unset it
				if ( isset( $lateVarNames[ $name ] ) !== ( $flag === self::JS_VAR_LATE ) ) {
					unset( $vars[ $name ] );
				}
			}
		}

		return $vars;
	}

	/**
	 * Get the revision ID for the last user talk page revision viewed by the talk page owner.
	 *
	 * @return int|null
	 */
	private function getLastSeenUserTalkRevId() {
		$services = MediaWikiServices::getInstance();
		$user = $this->getUser();
		$userHasNewMessages = $services
			->getTalkPageNotificationManager()
			->userHasNewMessages( $user );
		if ( !$userHasNewMessages ) {
			return null;
		}

		$timestamp = $services
			->getTalkPageNotificationManager()
			->getLatestSeenMessageTimestamp( $user );
		if ( !$timestamp ) {
			return null;
		}

		$revRecord = $services->getRevisionLookup()->getRevisionByTimestamp(
			$user->getTalkPage(),
			$timestamp
		);
		return $revRecord ? $revRecord->getId() : null;
	}

	/**
	 * To make it harder for someone to slip a user a fake
	 * JavaScript or CSS preview, a random token
	 * is associated with the login session. If it's not
	 * passed back with the preview request, we won't render
	 * the code.
	 *
	 * @return bool
	 */
	public function userCanPreview() {
		$request = $this->getRequest();
		if (
			$request->getRawVal( 'action' ) !== 'submit' ||
			!$request->wasPosted()
		) {
			return false;
		}

		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			// Anons have predictable edit tokens
			return false;
		}
		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			return false;
		}

		$title = $this->getTitle();
		if ( !$this->getAuthority()->probablyCan( 'edit', $title ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return array Array in format "link name or number => 'link html'".
	 */
	public function getHeadLinksArray() {
		$tags = [];
		$config = $this->getConfig();

		if ( $this->cspOutputMode === self::CSP_META ) {
			foreach ( $this->CSP->getDirectives() as $header => $directive ) {
				$tags["meta-csp-$header"] = Html::element( 'meta', [
					'http-equiv' => $header,
					'content' => $directive,
				] );
			}
		}

		$tags['meta-generator'] = Html::element( 'meta', [
			'name' => 'generator',
			'content' => 'MediaWiki ' . MW_VERSION,
		] );

		if ( $config->get( MainConfigNames::ReferrerPolicy ) !== false ) {
			// Per https://w3c.github.io/webappsec-referrer-policy/#unknown-policy-values
			// fallbacks should come before the primary value so we need to reverse the array.
			foreach ( array_reverse( (array)$config->get( MainConfigNames::ReferrerPolicy ) ) as $i => $policy ) {
				$tags["meta-referrer-$i"] = Html::element( 'meta', [
					'name' => 'referrer',
					'content' => $policy,
				] );
			}
		}

		$p = $this->getRobotsContent();
		if ( $p ) {
			// http://www.robotstxt.org/wc/meta-user.html
			// Only show if it's different from the default robots policy
			$tags['meta-robots'] = Html::element( 'meta', [
				'name' => 'robots',
				'content' => $p,
			] );
		}

		# Browser based phonenumber detection
		if ( $config->get( MainConfigNames::BrowserFormatDetection ) !== false ) {
			$tags['meta-format-detection'] = Html::element( 'meta', [
				'name' => 'format-detection',
				'content' => $config->get( MainConfigNames::BrowserFormatDetection ),
			] );
		}

		foreach ( $this->mMetatags as [ $name, $val ] ) {
			$attrs = [];
			if ( strncasecmp( $name, 'http:', 5 ) === 0 ) {
				$name = substr( $name, 5 );
				$attrs['http-equiv'] = $name;
			} elseif ( strncasecmp( $name, 'og:', 3 ) === 0 ) {
				$attrs['property'] = $name;
			} else {
				$attrs['name'] = $name;
			}
			$attrs['content'] = $val;
			$tagName = "meta-$name";
			if ( isset( $tags[$tagName] ) ) {
				$tagName .= $val;
			}
			$tags[$tagName] = Html::element( 'meta', $attrs );
		}

		foreach ( $this->mLinktags as $tag ) {
			$tags[] = Html::element( 'link', $tag );
		}

		# Generally the order of the favicon and apple-touch-icon links
		# should not matter, but Konqueror (3.5.9 at least) incorrectly
		# uses whichever one appears later in the HTML source. Make sure
		# apple-touch-icon is specified first to avoid this.
		if ( $config->get( MainConfigNames::AppleTouchIcon ) !== false ) {
			$tags['apple-touch-icon'] = Html::element( 'link', [
				'rel' => 'apple-touch-icon',
				'href' => $config->get( MainConfigNames::AppleTouchIcon )
			] );
		}

		if ( $config->get( MainConfigNames::Favicon ) !== false ) {
			$tags['favicon'] = Html::element( 'link', [
				'rel' => 'icon',
				'href' => $config->get( MainConfigNames::Favicon )
			] );
		}

		$services = MediaWikiServices::getInstance();

		$tags = array_merge(
			$tags,
			$this->getHeadLinksCanonicalURLArray( $config ),
			$this->getHeadLinksAlternateURLsArray(),
			$this->getHeadLinksCopyrightArray( $config ),
			$this->getHeadLinksSyndicationArray( $config ),
		);

		// Allow extensions to add, remove and/or otherwise manipulate these links
		// If you want only to *add* <head> links, please use the addHeadItem()
		// (or addHeadItems() for multiple items) method instead.
		// This hook is provided as a last resort for extensions to modify these
		// links before the output is sent to client.
		$this->getHookRunner()->onOutputPageAfterGetHeadLinksArray( $tags, $this );

		return $tags;
	}

	/**
	 * Canonical URL and alternate URLs
	 *
	 * isCanonicalUrlAction affects all requests where "setArticleRelated" is true.
	 * This is typically all requests that show content (query title, curid, oldid, diff),
	 *  and all wikipage actions (edit, delete, purge, info, history etc.).
	 * It does not apply to file pages and special pages.
	 * 'history' and 'info' actions address page metadata rather than the page
	 *  content itself, so they may not be canonicalized to the view page url.
	 * TODO: this logic should be owned by Action subclasses.
	 * See T67402
	 */

	/**
	 * Get head links relating to the canonical URL
	 * Note: There should only be one canonical URL.
	 * @param Config $config
	 * @return array
	 */
	private function getHeadLinksCanonicalURLArray( Config $config ) {
		$tags = [];
		$canonicalUrl = $this->mCanonicalUrl;

		if ( $config->get( MainConfigNames::EnableCanonicalServerLink ) ) {
			$query = [];
			$action = $this->getContext()->getActionName();
			$isCanonicalUrlAction = in_array( $action, [ 'history', 'info' ] );
			$services = MediaWikiServices::getInstance();
			$languageConverterFactory = $services->getLanguageConverterFactory();
			$isLangConversionDisabled = $languageConverterFactory->isConversionDisabled();
			$pageLang = $this->getTitle()->getPageLanguage();
			$pageLanguageConverter = $languageConverterFactory->getLanguageConverter( $pageLang );
			$urlVariant = $pageLanguageConverter->getURLVariant();

			if ( $canonicalUrl !== false ) {
				$canonicalUrl = (string)$services->getUrlUtils()->expand( $canonicalUrl, PROTO_CANONICAL );
			} elseif ( $this->isArticleRelated() ) {
				if ( $isCanonicalUrlAction ) {
					$query['action'] = $action;
				} elseif ( !$isLangConversionDisabled && $urlVariant ) {
					# T54429, T108443: Making canonical URL language-variant-aware.
					$query['variant'] = $urlVariant;
				}
				$canonicalUrl = $this->getTitle()->getCanonicalURL( $query );
			} else {
				$reqUrl = $this->getRequest()->getRequestURL();
				$canonicalUrl = (string)$services->getUrlUtils()->expand( $reqUrl, PROTO_CANONICAL );
			}
		}

		if ( $canonicalUrl !== false ) {
			$tags['link-canonical'] = Html::element( 'link', [
				'rel' => 'canonical',
				'href' => $canonicalUrl
			] );
		}

		return $tags;
	}

	/**
	 * Get head links relating to alternate URL(s) in languages including language variants
	 * Output fully-qualified URL since meta alternate URLs must be fully-qualified
	 * Per https://developers.google.com/search/docs/advanced/crawling/localized-versions
	 * See T294716
	 *
	 * @return array
	 */
	private function getHeadLinksAlternateURLsArray() {
		$tags = [];
		$languageUrls = [];
		$action = $this->getContext()->getActionName();
		$isCanonicalUrlAction = in_array( $action, [ 'history', 'info' ] );
		$services = MediaWikiServices::getInstance();
		$languageConverterFactory = $services->getLanguageConverterFactory();
		$isLangConversionDisabled = $languageConverterFactory->isConversionDisabled();
		$pageLang = $this->getTitle()->getPageLanguage();
		$pageLanguageConverter = $languageConverterFactory->getLanguageConverter( $pageLang );

		# Language variants
		if (
			$this->isArticleRelated() &&
			!$isCanonicalUrlAction &&
			$pageLanguageConverter->hasVariants() &&
			!$isLangConversionDisabled
		) {
			$variants = $pageLanguageConverter->getVariants();
			foreach ( $variants as $variant ) {
				$bcp47 = LanguageCode::bcp47( $variant );
				$languageUrls[$bcp47] = $this->getTitle()
					->getFullURL( [ 'variant' => $variant ], false, PROTO_CURRENT );
			}
		}

		# Alternate URLs for interlanguage links would be handeled in HTML body tag instead of
		#  head tag, see T326829.

		if ( $languageUrls ) {
			# Force the alternate URL of page language code to be self.
			# T123901, T305540, T108443: Override mixed-variant variant link in language variant links.
			$currentUrl = $this->getTitle()->getFullURL( [], false, PROTO_CURRENT );
			$pageLangCodeBcp47 = LanguageCode::bcp47( $pageLang->getCode() );
			$languageUrls[$pageLangCodeBcp47] = $currentUrl;

			ksort( $languageUrls );

			# Also add x-default link per https://support.google.com/webmasters/answer/189077?hl=en
			$languageUrls['x-default'] = $currentUrl;

			# Process all of language variants and interlanguage links
			foreach ( $languageUrls as $bcp47 => $languageUrl ) {
				$bcp47lowercase = strtolower( $bcp47 );
				$tags['link-alternate-language-' . $bcp47lowercase] = Html::element( 'link', [
					'rel' => 'alternate',
					'hreflang' => $bcp47,
					'href' => $languageUrl,
				] );
			}
		}

		return $tags;
	}

	/**
	 * Get head links relating to copyright
	 *
	 * @param Config $config
	 * @return array
	 */
	private function getHeadLinksCopyrightArray( Config $config ) {
		$tags = [];

		if ( $this->copyrightUrl !== null ) {
			$copyright = $this->copyrightUrl;
		} else {
			$copyright = '';
			if ( $config->get( MainConfigNames::RightsPage ) ) {
				$copy = Title::newFromText( $config->get( MainConfigNames::RightsPage ) );

				if ( $copy ) {
					$copyright = $copy->getLocalURL();
				}
			}

			if ( !$copyright && $config->get( MainConfigNames::RightsUrl ) ) {
				$copyright = $config->get( MainConfigNames::RightsUrl );
			}
		}

		if ( $copyright ) {
			$tags['copyright'] = Html::element( 'link', [
				'rel' => 'license',
				'href' => $copyright
			] );
		}

		return $tags;
	}

	/**
	 * Get head links relating to syndication feeds.
	 *
	 * @param Config $config
	 * @return array
	 */
	private function getHeadLinksSyndicationArray( Config $config ) {
		if ( !$config->get( MainConfigNames::Feed ) ) {
			return [];
		}

		$tags = [];
		$feedLinks = [];

		foreach ( $this->getSyndicationLinks() as $format => $link ) {
			# Use the page name for the title.  In principle, this could
			# lead to issues with having the same name for different feeds
			# corresponding to the same page, but we can't avoid that at
			# this low a level.

			$feedLinks[] = $this->feedLink(
				$format,
				$link,
				# Used messages: 'page-rss-feed' and 'page-atom-feed' (for an easier grep)
				$this->msg(
					"page-{$format}-feed", $this->getTitle()->getPrefixedText()
				)->text()
			);
		}

		# Recent changes feed should appear on every page (except recentchanges,
		# that would be redundant). Put it after the per-page feed to avoid
		# changing existing behavior. It's still available, probably via a
		# menu in your browser. Some sites might have a different feed they'd
		# like to promote instead of the RC feed (maybe like a "Recent New Articles"
		# or "Breaking news" one). For this, we see if $wgOverrideSiteFeed is defined.
		# If so, use it instead.
		$sitename = $config->get( MainConfigNames::Sitename );
		$overrideSiteFeed = $config->get( MainConfigNames::OverrideSiteFeed );
		if ( $overrideSiteFeed ) {
			foreach ( $overrideSiteFeed as $type => $feedUrl ) {
				// Note, this->feedLink escapes the url.
				$feedLinks[] = $this->feedLink(
					$type,
					$feedUrl,
					$this->msg( "site-{$type}-feed", $sitename )->text()
				);
			}
		} elseif ( !$this->getTitle()->isSpecial( 'Recentchanges' ) ) {
			$rctitle = SpecialPage::getTitleFor( 'Recentchanges' );
			foreach ( $this->getAdvertisedFeedTypes() as $format ) {
				$feedLinks[] = $this->feedLink(
					$format,
					$rctitle->getLocalURL( [ 'feed' => $format ] ),
					# For grep: 'site-rss-feed', 'site-atom-feed'
					$this->msg( "site-{$format}-feed", $sitename )->text()
				);
			}
		}

		# Allow extensions to change the list pf feeds. This hook is primarily for changing,
		# manipulating or removing existing feed tags. If you want to add new feeds, you should
		# use OutputPage::addFeedLink() instead.
		$this->getHookRunner()->onAfterBuildFeedLinks( $feedLinks );

		$tags += $feedLinks;

		return $tags;
	}

	/**
	 * Generate a "<link rel/>" for a feed.
	 *
	 * @param string $type Feed type
	 * @param string $url URL to the feed
	 * @param string $text Value of the "title" attribute
	 * @return string HTML fragment
	 */
	private function feedLink( $type, $url, $text ) {
		return '';
	}

	/**
	 * Add a local or specified stylesheet, with the given media options.
	 * Internal use only. Use OutputPage::addModuleStyles() if possible.
	 *
	 * @param string $style URL to the file
	 * @param string $media To specify a media type, 'screen', 'printable', 'handheld' or any.
	 * @param string $condition For IE conditional comments, specifying an IE version
	 * @param string $dir Set to 'rtl' or 'ltr' for direction-specific sheets
	 */
	public function addStyle( $style, $media = '', $condition = '', $dir = '' ) {
		$options = [];
		if ( $media ) {
			$options['media'] = $media;
		}
		if ( $condition ) {
			$options['condition'] = $condition;
		}
		if ( $dir ) {
			$options['dir'] = $dir;
		}
		$this->styles[$style] = $options;
	}

	/**
	 * Adds inline CSS styles
	 * Internal use only. Use OutputPage::addModuleStyles() if possible.
	 *
	 * @param mixed $style_css Inline CSS
	 * @param-taint $style_css exec_html
	 * @param string $flip Set to 'flip' to flip the CSS if needed
	 */
	public function addInlineStyle( $style_css, $flip = 'noflip' ) {
		if ( $flip === 'flip' && $this->getLanguage()->isRTL() ) {
			# If wanted, and the interface is right-to-left, flip the CSS
			$style_css = CSSJanus::transform( $style_css, true, false );
		}
		$this->mInlineStyles .= Html::inlineStyle( $style_css );
	}

	/**
	 * Build exempt modules and legacy non-ResourceLoader styles.
	 *
	 * @return string|WrappedStringList HTML
	 */
	protected function buildExemptModules() {
		$chunks = [];

		// Requirements:
		// - Within modules provided by the software (core, skin, extensions),
		//   styles from skin stylesheets should be overridden by styles
		//   from modules dynamically loaded with JavaScript.
		// - Styles from site-specific, private, and user modules should override
		//   both of the above.
		//
		// The effective order for stylesheets must thus be:
		// 1. Page style modules, formatted server-side by RL\ClientHtml.
		// 2. Dynamically-loaded styles, inserted client-side by mw.loader.
		// 3. Styles that are site-specific, private or from the user, formatted
		//    server-side by this function.
		//
		// The 'ResourceLoaderDynamicStyles' marker helps JavaScript know where
		// point #2 is.

		// Add legacy styles added through addStyle()/addInlineStyle() here
		$chunks[] = implode( '', $this->buildCssLinksArray() ) . $this->mInlineStyles;

		// Things that go after the ResourceLoaderDynamicStyles marker
		$append = [];
		$separateReq = [ 'site.styles', 'user.styles' ];
		foreach ( $this->rlExemptStyleModules as $moduleNames ) {
			if ( $moduleNames ) {
				$append[] = $this->makeResourceLoaderLink(
					array_diff( $moduleNames, $separateReq ),
					RL\Module::TYPE_STYLES
				);

				foreach ( array_intersect( $moduleNames, $separateReq ) as $name ) {
					// These require their own dedicated request in order to support "@import"
					// syntax, which is incompatible with concatenation. (T147667, T37562)
					$append[] = $this->makeResourceLoaderLink( $name,
						RL\Module::TYPE_STYLES
					);
				}
			}
		}
		if ( $append ) {
			$chunks[] = Html::element(
				'meta',
				[ 'name' => 'ResourceLoaderDynamicStyles', 'content' => '' ]
			);
			$chunks = array_merge( $chunks, $append );
		}

		return self::combineWrappedStrings( $chunks );
	}

	/**
	 * @return array
	 */
	public function buildCssLinksArray() {
		$links = [];

		foreach ( $this->styles as $file => $options ) {
			$link = $this->styleLink( $file, $options );
			if ( $link ) {
				$links[$file] = $link;
			}
		}
		return $links;
	}

	/**
	 * Generate \<link\> tags for stylesheets
	 *
	 * @param string $style URL to the file
	 * @param array $options Option, can contain 'condition', 'dir', 'media' keys
	 * @return string HTML fragment
	 */
	protected function styleLink( $style, array $options ) {
		if ( isset( $options['dir'] ) && $this->getLanguage()->getDir() != $options['dir'] ) {
			return '';
		}

		if ( isset( $options['media'] ) ) {
			$media = self::transformCssMedia( $options['media'] );
			if ( $media === null ) {
				return '';
			}
		} else {
			$media = 'all';
		}

		if ( str_starts_with( $style, '/' ) ||
			str_starts_with( $style, 'http:' ) ||
			str_starts_with( $style, 'https:' )
		) {
			$url = $style;
		} else {
			$config = $this->getConfig();
			// Append file hash as query parameter
			$url = self::transformResourcePath(
				$config,
				$config->get( MainConfigNames::StylePath ) . '/' . $style
			);
		}

		$link = Html::linkedStyle( $url, $media );

		if ( isset( $options['condition'] ) ) {
			$condition = htmlspecialchars( $options['condition'] );
			$link = "<!--[if $condition]>$link<![endif]-->";
		}
		return $link;
	}

	/**
	 * Transform path to web-accessible static resource.
	 *
	 * This is used to add a validation hash as query string.
	 * This aids various behaviors:
	 *
	 * - Put long Cache-Control max-age headers on responses for improved
	 *   cache performance.
	 * - Get the correct version of a file as expected by the current page.
	 * - Instantly get the updated version of a file after deployment.
	 *
	 * Avoid using this for urls included in HTML as otherwise clients may get different
	 * versions of a resource when navigating the site depending on when the page was cached.
	 * If changes to the url propagate, this is not a problem (e.g. if the url is in
	 * an external stylesheet).
	 *
	 * @since 1.27
	 * @param Config $config
	 * @param string $path Path-absolute URL to file (from document root, must start with "/")
	 * @return string URL
	 */
	public static function transformResourcePath( Config $config, $path ) {
		$localDir = $config->get( MainConfigNames::BaseDirectory );
		$remotePathPrefix = $config->get( MainConfigNames::ResourceBasePath );
		if ( $remotePathPrefix === '' ) {
			// The configured base path is required to be empty string for
			// wikis in the domain root
			$remotePath = '/';
		} else {
			$remotePath = $remotePathPrefix;
		}
		if ( !str_starts_with( $path, $remotePath ) || str_starts_with( $path, '//' ) ) {
			// - Path is outside wgResourceBasePath, ignore.
			// - Path is protocol-relative. Fixes T155310. Not supported by RelPath lib.
			return $path;
		}
		// For files in resources, extensions/ or skins/, ResourceBasePath is preferred here.
		// For other misc files in $IP, we'll fallback to that as well. There is, however, a fourth
		// supported dir/path pair in the configuration (wgUploadDirectory, wgUploadPath)
		// which is not expected to be in wgResourceBasePath on CDNs. (T155146)
		$uploadPath = $config->get( MainConfigNames::UploadPath );
		if ( strpos( $path, $uploadPath ) === 0 ) {
			$localDir = $config->get( MainConfigNames::UploadDirectory );
			$remotePathPrefix = $remotePath = $uploadPath;
		}

		$path = RelPath::getRelativePath( $path, $remotePath );
		return self::transformFilePath( $remotePathPrefix, $localDir, $path );
	}

	/**
	 * Utility method for transformResourceFilePath().
	 *
	 * Caller is responsible for ensuring the file exists. Emits a PHP warning otherwise.
	 *
	 * @since 1.27
	 * @param string $remotePathPrefix URL path prefix that points to $localPath
	 * @param string $localPath File directory exposed at $remotePath
	 * @param string $file Path to target file relative to $localPath
	 * @return string URL
	 */
	public static function transformFilePath( $remotePathPrefix, $localPath, $file ) {
		// This MUST match the equivalent logic in CSSMin::remapOne()
		$localFile = "$localPath/$file";
		$url = "$remotePathPrefix/$file";
		if ( is_file( $localFile ) ) {
			$hash = md5_file( $localFile );
			if ( $hash === false ) {
				wfLogWarning( __METHOD__ . ": Failed to hash $localFile" );
				$hash = '';
			}
			$url .= '?' . substr( $hash, 0, 5 );
		}
		return $url;
	}

	/**
	 * Transform "media" attribute based on request parameters
	 *
	 * @param string $media Current value of the "media" attribute
	 * @return string|null Modified value of the "media" attribute, or null to disable
	 * this stylesheet
	 */
	public static function transformCssMedia( $media ) {
		global $wgRequest;

		if ( $wgRequest->getBool( 'printable' ) ) {
			// When browsing with printable=yes, apply "print" media styles
			// as if they are screen styles (no media, media="").
			if ( $media === 'print' ) {
				return '';
			}

			// https://www.w3.org/TR/css3-mediaqueries/#syntax
			//
			// This regex will not attempt to understand a comma-separated media_query_list
			// Example supported values for $media:
			//
			//     'screen', 'only screen', 'screen and (min-width: 982px)' ),
			//
			// Example NOT supported value for $media:
			//
			//     '3d-glasses, screen, print and resolution > 90dpi'
			//
			// If it's a "printable" request, we disable all screen stylesheets.
			$screenMediaQueryRegex = '/^(?:only\s+)?screen\b/i';
			if ( preg_match( $screenMediaQueryRegex, $media ) === 1 ) {
				return null;
			}
		}

		return $media;
	}

	/**
	 * Add a wikitext-formatted message to the output.
	 *
	 * @param string $name Message key
	 * @param mixed ...$args Message parameters. Unlike wfMessage(), this method only accepts
	 *     variadic parameters (they can't be passed as a single array parameter).
	 */
	public function addWikiMsg( $name, ...$args ) {
		$this->addWikiMsgArray( $name, $args );
	}

	/**
	 * Add a wikitext-formatted message to the output.
	 *
	 * @param string $name Message key
	 * @param array $args Message parameters. Unlike wfMessage(), this method only accepts
	 *     the parameters as an array (they can't be passed as variadic parameters),
	 *     or just a single parameter (this only works by accident, don't rely on it).
	 */
	public function addWikiMsgArray( $name, $args ) {
		$this->addHTML( $this->msg( $name, $args )->parseAsBlock() );
	}

	/**
	 * This function takes a number of message/argument specifications, wraps them in
	 * some overall structure, and then parses the result and adds it to the output.
	 *
	 * In the $wrap, $1 is replaced with the first message, $2 with the second,
	 * and so on. The subsequent arguments may be either
	 * 1) strings, in which case they are message names, or
	 * 2) arrays, in which case, within each array, the first element is the message
	 *    name, and subsequent elements are the parameters to that message.
	 *
	 * Don't use this for messages that are not in the user's interface language.
	 *
	 * For example:
	 *
	 *     $wgOut->wrapWikiMsg( "<div class='customclass'>\n$1\n</div>", 'some-msg-key' );
	 *
	 * Is equivalent to:
	 *
	 *     $wgOut->addWikiTextAsInterface( "<div class='customclass'>\n"
	 *         . wfMessage( 'some-msg-key' )->plain() . "\n</div>" );
	 *
	 * The newline after the opening div is needed in some wikitext. See T21226.
	 *
	 * @param string $wrap
	 * @param mixed ...$msgSpecs
	 */
	public function wrapWikiMsg( $wrap, ...$msgSpecs ) {
		$s = $wrap;
		foreach ( $msgSpecs as $n => $spec ) {
			if ( is_array( $spec ) ) {
				$args = $spec;
				$name = array_shift( $args );
			} else {
				$args = [];
				$name = $spec;
			}
			$s = str_replace( '$' . ( $n + 1 ), $this->msg( $name, $args )->plain(), $s );
		}
		$this->addWikiTextAsInterface( $s );
	}

	/**
	 * Whether the output has a table of contents when the ToC is
	 * rendered inline.
	 * @return bool
	 * @since 1.22
	 */
	public function isTOCEnabled() {
		return $this->mEnableTOC;
	}

	/**
	 * Helper function to setup the PHP implementation of OOUI to use in this request.
	 *
	 * @since 1.26
	 * @param string|null $skinName Ignored since 1.41
	 * @param string|null $dir Ignored since 1.41
	 */
	public static function setupOOUI( $skinName = null, $dir = null ) {
		if ( !self::$oouiSetupDone ) {
			self::$oouiSetupDone = true;
			$context = RequestContext::getMain();
			$skinName = $context->getSkinName();
			$dir = $context->getLanguage()->getDir();
			$themes = RL\OOUIFileModule::getSkinThemeMap();
			$theme = $themes[$skinName] ?? $themes['default'];
			// For example, 'OOUI\WikimediaUITheme'.
			$themeClass = "OOUI\\{$theme}Theme";
			Theme::setSingleton( new $themeClass() );
			Element::setDefaultDir( $dir );
		}
	}

	/**
	 * Notify of a change in global skin or language which would necessitate
	 * reinitialization of OOUI global static data.
	 * @internal
	 */
	public static function resetOOUI() {
		if ( self::$oouiSetupDone ) {
			self::$oouiSetupDone = false;
			self::setupOOUI();
		}
	}

	/**
	 * Add ResourceLoader module styles for OOUI and set up the PHP implementation of it for use with
	 * MediaWiki and this OutputPage instance.
	 *
	 * @since 1.25
	 */
	public function enableOOUI() {
		self::setupOOUI();
		$this->addModuleStyles( [
			'oojs-ui-core.styles',
			'oojs-ui.styles.indicators',
			'mediawiki.widgets.styles',
			'oojs-ui-core.icons',
		] );
	}

	/**
	 * Get (and set if not yet set) the CSP nonce.
	 *
	 * This value needs to be included in any <script> tags on the
	 * page.
	 *
	 * @return string|false Nonce or false to mean don't output nonce
	 * @since 1.32
	 * @deprecated Since 1.35 use getCSP()->getNonce() instead
	 */
	public function getCSPNonce() {
		wfDeprecated( __METHOD__, '1.35' );
		return $this->CSP->getNonce();
	}

	/**
	 * Get the ContentSecurityPolicy object
	 *
	 * @since 1.35
	 * @return ContentSecurityPolicy
	 */
	public function getCSP() {
		return $this->CSP;
	}

	/**
	 * Sets the output mechanism for content security policies (HTTP headers or meta tags).
	 * Defaults to HTTP headers; in most cases this should not be changed.
	 *
	 * Meta mode should not be used together with setArticleBodyOnly() as meta tags and other
	 * headers are not output when that flag is set.
	 *
	 * @param string $mode One of the CSP_* constants
	 * @phan-param 'headers'|'meta' $mode
	 * @return void
	 * @see self::CSP_HEADERS
	 * @see self::CSP_META
	 */
	public function setCspOutputMode( string $mode ): void {
		$this->cspOutputMode = $mode;
	}

	/**
	 * The final bits that go to the bottom of a page
	 * HTML document including the closing tags
	 *
	 * @internal
	 * @since 1.37
	 * @param Skin $skin
	 * @return string
	 */
	public function tailElement( $skin ) {
		$tail = [
			MWDebug::getDebugHTML( $skin ),
			$this->getBottomScripts(),
			MWDebug::getHTMLDebugLog(),
			Html::closeElement( 'body' ),
			Html::closeElement( 'html' ),
		];

		return WrappedStringList::join( "\n", $tail );
	}
}

/** @deprecated class alias since 1.41 */
class_alias( OutputPage::class, 'OutputPage' );

<?php
/**
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

use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Skin\SkinComponentTempUserBanner;
use MediaWiki\Skin\SkinComponentUtils;
use MediaWiki\Title\Title;

/**
 * Generic template for use with Mustache templates.
 * @since 1.35
 */
class SkinMustache extends SkinTemplate {
	/**
	 * @var TemplateParser|null
	 */
	private $templateParser = null;

	/**
	 * Get the template parser, it will be lazily created if not already set.
	 * The template directory is defined in the skin options passed to
	 * the class constructor.
	 *
	 * @return TemplateParser
	 */
	protected function getTemplateParser() {
		if ( $this->templateParser === null ) {
			$this->templateParser = new TemplateParser( $this->options['templateDirectory'] );
			// For table of contents rendering.
			$this->templateParser->enableRecursivePartials( true );
		}
		return $this->templateParser;
	}

	/**
	 * Creates a banner notifying IP masked users (temporary accounts)
	 * That they are editing via a temporary account.
	 *
	 * @return string
	 */
	private function createTempUserBannerHTML() {
		$isSupportedSkin = $this->getOptions()['tempUserBanner'];
		$isTempUser = $this->getUser()->isTemp();

		if ( !$isSupportedSkin || !$isTempUser ) {
			return '';
		}

		$returntoParam = SkinComponentUtils::getReturnToParam(
			$this->getTitle(),
			$this->getRequest(),
			$this->getAuthority()
		);

		$tempUserBanner = new SkinComponentTempUserBanner(
			$returntoParam,
			$this->getContext(),
			$this->getUser(),
		);
		return $tempUserBanner->getTemplateData()['html'];
	}

	/**
	 * @inheritDoc
	 * Render the associated template. The master template is assumed
	 * to be 'skin' unless `template` has been passed in the skin options
	 * to the constructor.
	 */
	public function generateHTML() {
		$this->setupTemplateContext();
		$out = $this->getOutput();
		$tp = $this->getTemplateParser();
		$template = $this->options['template'] ?? 'skin';
		$data = $this->getTemplateData();
		$tempUserBannerHTML = $this->createTempUserBannerHTML();

		// T259955: OutputPage::headElement must be called last (after getTemplateData)
		// as it calls OutputPage::getRlClient, which freezes the ResourceLoader
		// modules queue for the current page load.
		$html = $out->headElement( $this );

		$html .= $tempUserBannerHTML;

		$html .= $tp->processTemplate( $template, $data );
		$html .= $out->tailElement( $this );
		return $html;
	}

	/**
	 * @inheritDoc
	 */
	protected function doEditSectionLinksHTML( array $links, Language $lang ) {
		$template = $this->getOptions()['templateSectionLinks'] ?? null;
		if ( !$template ) {
			return parent::doEditSectionLinksHTML( $links, $lang );
		}
		return $this->getTemplateParser()->processTemplate( $template, [
			'class' => 'mw-editsection',
			'array-links' => $links
		] );
	}

	/**
	 * @inheritDoc
	 * @return array Data specific for a mustache template. See parent function for common data.
	 */
	public function getTemplateData() {
		$out = $this->getOutput();
		$printSource = Html::rawElement(
			'div',
			[
				'class' => 'printfooter',
				'data-nosnippet' => ''
			] + $this->getUserLanguageAttributes(),
			$this->printSource()
		);
		$bodyContent = $out->getHTML();

		$newTalksHtml = $this->getNewtalks() ?: null;

		$data = parent::getTemplateData() + [
			// Array objects
			'array-indicators' => $this->getIndicatorsData( $out->getIndicators() ),
			// HTML strings
			'html-site-notice' => $this->getSiteNotice() ?: null,
			'html-user-message' => $newTalksHtml ?
				Html::rawElement( 'div', [ 'class' => 'usermessage' ], $newTalksHtml ) : null,
			'html-subtitle' => $this->prepareSubtitle(),
			'html-body-content' => $this->wrapHTML( $out->getTitle(), $bodyContent ),
			'html-categories' => $this->getCategories(),
			'html-after-content' => $this->afterContentHook(),
			'html-undelete-link' => $this->prepareUndeleteLink(),
			'html-user-language-attributes' => $this->prepareUserLanguageAttributes(),

			// links
			'link-mainpage' => Title::newMainPage()->getLocalURL(),
		];

		foreach ( $this->options['messages'] ?? [] as $message ) {
			$data["msg-{$message}"] = $this->msg( $message )->text();
		}
		return $data;
	}
}

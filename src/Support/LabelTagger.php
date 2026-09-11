<?php
/**
 * Teaching FluentCart's rendered status badges about custom statuses.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A measured workaround, not a guess.
 *
 * FluentCart 1.6.3 renders every status badge — admin order list, admin order
 * detail and the customer dashboard — as `<span class="badge <variant>">`,
 * and the text inside is the **raw column value, humanised in JavaScript**:
 * `us_warehouse` comes out as "Us Warehouse", `partially_refunded` as
 * "Partially Refunded", `processing` as "Processing". Verified in the browser
 * against 1.6.3 with `window.fluentCartAdminApp.order_statuses.processing` set
 * to "處理中" while the badge still said "Processing".
 *
 * So the label map behind `fluent_cart/order_statuses` reaches the status
 * dropdown, the Orders filter and every server-rendered surface — but not the
 * badge. Neither the slug nor a colour hook is in the badge markup either.
 *
 * This class closes both gaps from the outside: a small script matches the
 * badge's text against every spelling a given slug can be rendered as, stamps
 * `data-ys-status="<slug>"` on it (which `StatusCss` colours) and swaps the
 * text for the configured label. It only ever touches a badge whose text is one
 * of the known spellings of a status this plugin manages; everything else is
 * left exactly as FluentCart rendered it.
 */
final class LabelTagger {

	/**
	 * Text => `['s' => slug, 'l' => label]` for every spelling of every managed status.
	 *
	 * @param array $settings Normalised settings.
	 * @return array<string,array<string,string>>
	 */
	public static function map( array $settings ) {
		$statuses  = array();
		$ambiguous = array();

		// The order and shipping axes are independent, so the same slug can be
		// defined on both — with different labels. Nothing in the rendered badge
		// says which axis it came from, so a slug that means two things is left
		// alone rather than relabelled to whichever definition was read last.
		$claim = static function ( $slug, $label ) use ( &$statuses, &$ambiguous ) {
			if ( isset( $statuses[ $slug ] ) && $statuses[ $slug ] !== $label ) {
				$ambiguous[ $slug ] = true;
				return;
			}

			$statuses[ $slug ] = $label;
		};

		foreach ( array( 'order', 'shipping' ) as $axis ) {
			foreach ( $settings[ $axis ] as $definition ) {
				if ( ! empty( $definition['enabled'] ) ) {
					$claim( $definition['slug'], $definition['label'] );
				}
			}
		}

		foreach ( array( 'order', 'payment', 'shipping' ) as $axis ) {
			foreach ( $settings['overrides'][ $axis ] as $slug => $override ) {
				if ( '' !== $override['label'] ) {
					$claim( $slug, $override['label'] );
				}
			}
		}

		foreach ( array_keys( $ambiguous ) as $slug ) {
			unset( $statuses[ $slug ] );
		}

		$map      = array();
		$conflict = array();

		foreach ( $statuses as $slug => $label ) {
			foreach ( self::spellings( $slug, $label ) as $text ) {
				if ( isset( $map[ $text ] ) && $map[ $text ]['s'] !== $slug ) {
					// Two statuses that render identically: colouring or
					// relabelling either one would be a coin flip, so neither
					// gets touched.
					$conflict[ $text ] = true;
					continue;
				}

				$map[ $text ] = array(
					's' => $slug,
					'l' => $label,
				);
			}
		}

		foreach ( array_keys( $conflict ) as $text ) {
			unset( $map[ $text ] );
		}

		return $map;
	}

	/**
	 * Every string FluentCart (or this plugin, on a second pass) might render
	 * for one status.
	 *
	 * @param string $slug  Slug.
	 * @param string $label Configured label.
	 * @return string[]
	 */
	private static function spellings( $slug, $label ) {
		$spaced = str_replace( array( '-', '_' ), ' ', $slug );

		$out = array(
			// Already relabelled — makes a second pass a no-op instead of a loop.
			$label,
			$slug,
			$spaced,
			// "us warehouse" -> "Us Warehouse", which is what 1.6.3 prints.
			ucwords( $spaced ),
			ucfirst( $spaced ),
		);

		return array_values( array_unique( array_filter( array_map( 'trim', $out ), 'strlen' ) ) );
	}

	/**
	 * The tagger itself.
	 *
	 * @param array<string,array<string,string>> $map      Spelling map.
	 * @param string                             $selector CSS selector for candidate badges.
	 * @return string JavaScript, ready for `wp_add_inline_script`.
	 */
	public static function script( array $map, $selector ) {
		/**
		 * Whether the rendered badge text may be replaced with the configured
		 * label. Colours are applied either way.
		 *
		 * @param bool $enabled Default true.
		 */
		$relabel = apply_filters( 'ys_fct_status/patch_admin_labels', true );

		return '(function(){'
			. 'var M=' . wp_json_encode( $map ) . ';'
			. 'var REL=' . ( $relabel ? 'true' : 'false' ) . ';'
			. 'var SEL=' . wp_json_encode( $selector ) . ';'
			. 'if(!M||!Object.keys(M).length){return;}'
			. 'function setText(n,v){'
			// Replace the text node in place rather than assigning textContent:
			// the badge contains Vue's own comment anchor, and blowing that away
			// is how you get a component that can never patch itself again.
			. 'for(var i=0;i<n.childNodes.length;i++){var c=n.childNodes[i];'
			. 'if(c.nodeType===3&&c.nodeValue.trim()!==""){c.nodeValue=v;return;}}'
			. 'n.appendChild(document.createTextNode(v));}'
			. 'function tag(n){'
			. 'if(!n||n.children.length){return;}'
			. 'var k=(n.textContent||"").trim();'
			. 'var hit=Object.prototype.hasOwnProperty.call(M,k)?M[k]:null;'
			. 'if(!hit){if(n.hasAttribute("data-ys-status")){n.removeAttribute("data-ys-status");}return;}'
			. 'if(n.getAttribute("data-ys-status")!==hit.s){n.setAttribute("data-ys-status",hit.s);}'
			. 'if(REL&&hit.l&&k!==hit.l){setText(n,hit.l);}}'
			. 'function run(){try{var l=document.querySelectorAll(SEL);for(var i=0;i<l.length;i++){tag(l[i]);}}catch(e){}}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}'
			// The SPA re-renders its tables on every filter, sort and page
			// change, so one pass at load is never enough. Debounced, and the
			// observer is disconnected around our own writes so a relabel does
			// not schedule another pass.
			. 'var t=null,busy=false;'
			. 'var o=new MutationObserver(function(){if(busy){return;}if(t){clearTimeout(t);}'
			. 't=setTimeout(function(){busy=true;run();busy=false;},80);});'
			. 'o.observe(document.documentElement,{childList:true,subtree:true,characterData:true});'
			. '})();';
	}

	/**
	 * Slugs this plugin manages the appearance of.
	 *
	 * @param array $settings Normalised settings.
	 * @return bool Whether there is anything at all to do.
	 */
	public static function hasAnything( array $settings ) {
		if ( ! empty( $settings['order'] ) || ! empty( $settings['shipping'] ) ) {
			return true;
		}

		foreach ( array( 'order', 'payment', 'shipping' ) as $axis ) {
			if ( ! empty( $settings['overrides'][ $axis ] ) ) {
				return true;
			}
		}

		return false;
	}
}

<?php
/**
 * Version of THIS bundled copy of the POSIMYTH Analytics SDK.
 *
 * Read by posimyth-sdk-loader.php to decide which plugin's copy of the shared classes wins when
 * several POSIMYTH plugins are active. Bump it on ANY change to the shared SDK files, or an
 * updated plugin's fixes can silently lose to a sibling's older copy.
 *
 * 2.21.0 — a failed ping is no longer silent. do_request() discarded the hub's response entirely, so
 * a hub that accepted the request and then failed to store it was invisible to every product at once:
 * pings kept going out, rows stopped arriving, and nothing anywhere said so. It was found by probing
 * the endpoint by hand, which is not a monitoring strategy. Under WP_DEBUG, and only for the blocking
 * path where a status actually exists, a non-2xx reply now writes one line to the debug log. Nothing
 * changes for a site with debugging off.
 *
 * 2.20.0 — the reconciliation the previous revisions kept asking for. All four plugins now ship the
 * SAME three shared files, byte for byte apart from their text domain, and the SAME version number.
 * That restores the loader's own stated assumption ("the copies are byte-identical apart from their
 * text domain, so a tie has no wrong answer") and, with it, the point of this file: it now records
 * which BUILD a copy is, and no longer decides whose design or whose feature set every sibling gets.
 *
 * What was reconciled, and what each divergence had been costing:
 *
 *  - Consent notice: the 2.19.0 treatment is now everyone's. The accent arrives as an inline
 *    `--posi-accent` custom property on each instance's own markup and the stylesheet keys only on
 *    the stable `posi-*` classes, so one stylesheet serves N brand colours. Before this, three copies
 *    baked a literal colour and a product-specific class prefix into a stylesheet that is printed
 *    ONCE for the whole page — which is how The Plus Addons' purple painted Sticky Header Effects'
 *    notice, and Sticky Header Effects' raspberry painted The Plus Addons'. Never reintroduce a
 *    prefix-keyed selector or a literal colour here: the stylesheet's instance and the markup's
 *    instance are latched separately, so they are not guaranteed to be the same product.
 *
 *  - Deactivation survey: the 2.16.0 design (accent top border, backdrop blur, 575px dialog) plus
 *    callable `reasons`. The callable support existed only in the Nexter copies while a copy without
 *    it held the highest version, and a copy that only accepts arrays does not error on a closure —
 *    is_array() is simply false, so the config collapsed to the built-in seven. Nexter Extension and
 *    Nexter Blocks both pass a closure, so both silently shipped the generic reason list instead of
 *    their own branded cards. Callables are the supported way to defer __() out of `plugins_loaded`,
 *    so dropping that block also re-breaks WP 6.7+ translation timing.
 *
 * Every product passes its own `accent` (and the legacy `css_prefix`) explicitly, even where the
 * value equals this SDK's neutral default. Branding is never inherited: a product that passes nothing
 * is painted by whichever copy won the loader, and that is precisely the coupling this revision
 * removes. The defaults here stay product-neutral for the same reason.
 *
 * Keeping it this way: change the shared files in ONE place, re-sync all five, bump this number in
 * all five together. A copy that diverges again re-creates the exact failure mode above, and it fails
 * silently — nothing errors, a sibling's design or defaults simply take over.
 *
 * @package POSIMYTH\Analytics\SDK
 */

return '2.21.0';

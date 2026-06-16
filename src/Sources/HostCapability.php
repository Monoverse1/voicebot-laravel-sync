<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sources;

enum HostCapability: string
{
    case CatalogSearch = 'catalog.search';
    case CatalogGetProduct = 'catalog.get_product';
    case CatalogCompare = 'catalog.compare';
    case CatalogFacets = 'catalog.facets';
    case NavOpen = 'nav.open';
    case NavSearchPage = 'nav.search_page';
    case CartAdd = 'cart.add';
    case CartRemove = 'cart.remove';
    case CartUpdateQty = 'cart.update_qty';
    case CartView = 'cart.view';
    case CheckoutAssist = 'checkout.assist';
    case VariantSelect = 'variant.select';
    case FormSubmit = 'form.submit';
}

<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Dto;

/**
 * Canonical entity kinds accepted by the ingest projector. Mirrors the backend
 * _KIND_TO_MODEL (apps/api/.../ingest/parsers/entity_validators.py). Producer-side
 * kinds only — WordPress-specific kinds (form, popup, selector_map) are out of scope.
 */
enum EntityKind: string
{
    case Product = 'product';
    case Variation = 'variation';
    case Category = 'category';
    case Tag = 'tag';
    case Attribute = 'attribute';
    case Page = 'page';
    case Post = 'post';
    case Cpt = 'cpt';
    case Menu = 'menu';
    case MenuItem = 'menu_item';
    case Site = 'site';
    case ShippingMethod = 'shipping_method';
    case PaymentMethod = 'payment_method';

    /**
     * Payload keys the backend validator REQUIRES for this kind (entity_validators.py).
     * Doctor checks a sampled mapped row carries these before the first real push.
     *
     * @return list<string>
     */
    public function requiredPayloadKeys(): array
    {
        return match ($this) {
            self::Product, self::Category, self::Tag, self::Attribute, self::Menu => ['name'],
            self::Page, self::Post, self::Cpt => ['title'],
            self::MenuItem => ['label', 'menu_external_id'],
            self::Variation => ['parent_external_id'],
            self::ShippingMethod, self::PaymentMethod => ['label'],
            self::Site => [],
        };
    }
}

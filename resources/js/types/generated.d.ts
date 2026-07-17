declare namespace App {
namespace Data {
export type AccountSummaryData = {
userName: string,
userEmail: string,
userPhone: string | null,
orderCount: number,
addressCount: number,
loyaltyPoints: number,
defaultAddress: App.Data.AddressData | null,
};
export type AddressData = {
id: number,
label: string | null,
street: string,
street2: string | null,
city: string,
state: string,
postalCode: string,
country: string,
instructions: string | null,
isDefault: boolean,
};
export type AdminUserData = {
id: number,
name: string,
email: string,
};
export type CartData = {
id: number,
itemCount: number,
subtotalCents: number,
items: App.Data.CartItemData[],
};
export type CartItemData = {
id: number,
menuItemId: number,
menuItemName: string,
imageThumbUrl: string | null,
quantity: number,
unitPriceCents: number,
lineTotalCents: number,
selectionSummary: string,
selectionGroups: {
groupName: string,
selectionNames: string[],
}[],
isAvailable: boolean,
};
export type ItemTemplateData = {
id: number,
name: string,
description: string | null,
isActive: boolean,
position: number,
groups: App.Data.ItemTemplateGroupData[],
};
export type ItemTemplateGroupData = {
id: number,
name: string,
minSelections: number,
maxSelections: number | null,
position: number,
isSingleSelect: boolean,
isRequired: boolean,
options: App.Data.ItemTemplateOptionData[],
};
export type ItemTemplateOptionData = {
id: number,
name: string,
priceDeltaCents: number,
isAvailable: boolean,
position: number,
};
export type MenuCategoryData = {
id: number,
name: string,
slug: string,
description: string | null,
position: number,
items: App.Data.MenuItemData[],
};
export type MenuItemData = {
id: number,
menuCategoryId: number,
name: string,
slug: string,
description: string | null,
priceCents: number,
imageUrl: string | null,
imageMediumUrl: string | null,
imageThumbUrl: string | null,
isAvailable: boolean,
isFeatured: boolean,
position: number,
itemTemplateId: number | null,
template: App.Data.ItemTemplateData | null,
defaultSelectionIds: number[],
};
export type OrderData = {
id: number,
number: string,
status: string,
type: string,
customerName: string,
customerEmail: string,
customerPhone: string | null,
deliveryAddress: Record<string, any> | null,
subtotalCents: number,
taxCents: number,
tipCents: number,
tipRecipient: string,
deliveryFeeCents: number,
totalCents: number,
awardedLoyaltyPoints: number,
notes: string | null,
placedAt: string | null,
items: App.Data.OrderItemData[],
};
export type OrderEventData = {
id: number,
fromStatus: string | null,
toStatus: string,
occurredAt: string,
userName: string | null,
note: string | null,
};
export type OrderItemData = {
id: number,
name: string,
quantity: number,
unitPriceCents: number,
subtotalCents: number,
modifierSummary: string,
modifierGroups: {
groupName: string,
selectionNames: string[],
}[],
};
export type PendingInvitationData = {
id: number,
email: string,
expiresAt: string | null,
invitedByName: string | null,
role: App.Enums.RestaurantRole,
};
export type RestaurantData = {
id: number,
name: string,
subdomain: string,
customDomain: string | null,
description: string | null,
logoUrl: string | null,
logoMediumUrl: string | null,
logoThumbUrl: string | null,
heroImageUrl: string | null,
heroImageMediumUrl: string | null,
heroTagline: string | null,
heroCtaLabel: string | null,
heroCtaUrl: string | null,
aboutBody: string | null,
aboutImageUrl: string | null,
aboutImageMediumUrl: string | null,
primaryColor: string | null,
secondaryColor: string | null,
email: string | null,
phone: string | null,
street: string | null,
street2: string | null,
city: string | null,
state: string | null,
postalCode: string | null,
taxRatePercent: number,
applicationFeePercent: number,
deliveryFeeCents: number,
pickupRefundsEnabled: boolean,
deliveryRefundsEnabled: boolean,
deliveryEnabled: boolean,
selfDelivery: boolean,
isActive: boolean,
isLive: boolean,
isStripeReady: boolean,
timezone: string,
isOpen: boolean,
nextOpenLabel: string | null,
openStatusLabel: string | null,
socialLinks: Record<string, string>,
hoursByDay: {
opensAt: string,
closesAt: string,
position: number,
}[][],
createdAt: string | null,
publicUrl: string,
};
export type RestaurantHourData = {
dayOfWeek: number,
opensAt: string,
closesAt: string,
position: number,
};
export type RestaurantMemberData = {
id: number,
name: string,
email: string,
role: App.Enums.RestaurantRole,
};
export type RestaurantPhotoData = {
id: number,
caption: string | null,
position: number,
imageUrl: string | null,
imageMediumUrl: string | null,
imageThumbUrl: string | null,
};
}
namespace Enums {
export type AutoCancelRefundMode = 'auto' | 'manual';
export type DeliveryFallbackAction = 'try_next_provider' | 'hold_for_owner' | 'auto_cancel_refund';
export type DeliveryFeeStrategy = 'pass_through' | 'absorb';
export type DeliveryIntegrationStatus = 'connected' | 'disconnected' | 'error';
export type DeliveryMode = 'self' | 'third_party';
export type DeliveryProviderName = 'self' | 'doordash' | 'uber';
export type DeliveryStatus = 'pending' | 'driver_assigned' | 'picked_up' | 'delivered' | 'cancelled' | 'failed';
export type MenuImportStatus = 'queued' | 'processing' | 'needs_review' | 'completed' | 'failed';
export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'ready' | 'completed' | 'cancelled';
export type OrderType = 'delivery' | 'pickup';
export type PaymentState = 'captured' | 'authorized' | 'voided';
export type PosIntegrationStatus = 'connected' | 'disconnected' | 'token_expired' | 'error';
export type PosProviderName = 'square' | 'clover';
export type RestaurantRole = 'admin' | 'staff';
export type RestaurantStatus = 'pending_review' | 'approved' | 'active' | 'suspended';
export type RevenueRole = 'founder' | 'operator' | 'recruiter' | 'overseer' | 'delivery_margin';
export type SelfDeliveryTipRecipient = 'driver' | 'pool' | 'split_50_50';
export type TipRecipient = 'pool' | 'driver' | 'split';
}
}

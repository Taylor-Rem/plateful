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
export type ApiCallLogData = {
id: number,
channel: string,
action: string,
keyName: string | null,
keyPrefix: string | null,
userName: string | null,
arguments: Record<string, any> | null,
ok: boolean,
error: string | null,
durationMs: number,
createdAt: string,
};
export type ApiKeyCreatedData = {
key: App.Data.ApiKeyData,
plainTextKey: string,
};
export type ApiKeyData = {
id: number,
name: string,
keyPrefix: string,
scopes: string[],
isPlatform: boolean,
rateLimitPerMinute: number,
lastUsedAt: string | null,
expiresAt: string | null,
revokedAt: string | null,
createdByName: string | null,
createdAt: string,
};
export type AuthSessionData = {
token: string,
user: App.Data.MeData,
};
export type CampaignData = {
id: number,
subject: string,
preheader: string | null,
headline: string,
body: string,
offerCallout: string | null,
ctaLabel: string | null,
ctaUrl: string | null,
audienceFilter: {
type: string,
days?: number,
min_orders?: number,
},
audienceLabel: string,
status: App.Enums.CampaignStatus,
scheduledAt: string | null,
sentAt: string | null,
recipientsCount: number,
deliveredCount: number,
bouncedCount: number,
complainedCount: number,
unsubscribedCount: number,
createdAt: string | null,
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
selectedOptionIds: number[],
notes: string | null,
isAvailable: boolean,
};
export type CheckoutIntentData = {
pendingCheckoutId: number,
paymentIntentId: string,
clientSecret: string,
publishableKey: string,
stripeAccountId: string,
manualCapture: boolean,
subtotalCents: number,
taxCents: number,
deliveryFeeCents: number,
tipCents: number,
totalCents: number,
};
export type CustomerData = {
id: number,
name: string,
email: string,
phone: string | null,
totalOrders: number,
totalSpentCents: number,
firstOrderedAt: string | null,
lastOrderedAt: string | null,
loyaltyPoints: number,
marketingOptedIn: boolean,
marketingOptedInAt: string | null,
};
export type CustomerStatsData = {
repeatOrderPct: number | null,
repeatRevenuePct: number | null,
avgOrdersPerCustomer: number | null,
medianDaysBetweenOrders: number | null,
identifiedCustomers: number,
identifiedOrders: number,
monthly: App.Data.CustomerStatsMonthData[],
};
export type CustomerStatsMonthData = {
month: string,
newCents: number,
returningCents: number,
guestCents: number,
};
export type DashboardStatsData = {
ordersToday: number,
revenueTodayCents: number,
avgTicketCents: number | null,
pendingCount: number,
date: string,
timezone: string,
};
export type DeliveryAssignmentData = {
provider: string,
status: string,
providerStatus: string | null,
statusLabel: string,
isActive: boolean,
trackingUrl: string | null,
supportReference: string | null,
externalId: string | null,
driverName: string | null,
driverPhone: string | null,
pickupEtaAt: string | null,
dropoffEtaAt: string | null,
updatedAt: string | null,
};
export type DeviceTokenData = {
token: string,
platform: string,
deviceName: string | null,
lastSeenAt: string | null,
};
export type EarnerData = {
userId: number | null,
name: string,
email: string | null,
roles: Record<string, number>,
totalCents: number,
};
export type EarningsSummaryData = {
month: string,
monthLabel: string,
totalCents: number,
shares: Record<string, number>,
founder: {
id: number,
name: string,
} | null,
operator: {
id: number,
name: string,
} | null,
earners: App.Data.EarnerData[],
};
export type FeeDistributionData = {
id: number,
orderId: number,
orderNumber: string | null,
orderRefunded: boolean,
restaurantId: number,
restaurantName: string | null,
restaurantSubdomain: string | null,
userId: number | null,
userName: string | null,
userEmail: string | null,
role: string,
percent: number,
amountCents: number,
earnedAt: string,
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
kind: string,
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
kind: string,
ingredientId: number | null,
priceDeltaCents: number,
isAvailable: boolean,
position: number,
};
export type MeData = {
id: number,
name: string,
email: string,
phone: string | null,
avatar: string | null,
emailVerified: boolean,
twoFactorEnabled: boolean,
linkedProviders: string[],
createdAt: string,
pushOrderUpdates: boolean,
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
templateIds: number[],
groups: App.Data.ItemTemplateGroupData[],
ingredients: App.Data.MenuItemIngredientData[],
defaultSelectionIds: number[],
};
export type MenuItemIngredientData = {
id: number,
name: string,
position: number,
isRemovable: boolean,
allowHalf: boolean,
extraPriceCents: number | null,
swapTemplateId: number | null,
};
export type OperatorActorData = {
type: string,
name: string,
isPlatform: boolean,
scopes: string[],
restaurants: App.Data.OperatorRestaurantData[],
};
export type OperatorOrderData = {
order: App.Data.OrderData,
paymentState: string | null,
refundedAt: string | null,
refundedCents: number,
posProvider: string | null,
posPushedAt: string | null,
updatedAt: string | null,
events: App.Data.OrderEventData[],
};
export type OperatorRestaurantData = {
id: number,
name: string,
subdomain: string,
status: string,
isActive: boolean,
isLive: boolean,
isStripeReady: boolean,
deliveryEnabled: boolean,
city: string | null,
state: string | null,
timezone: string,
publicUrl: string,
role: string | null,
scopes: string[],
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
delivery: App.Data.DeliveryAssignmentData | null,
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
export type OrderHistoryItemData = {
id: number,
number: string,
status: string,
type: string,
totalCents: number,
itemCount: number,
placedAt: string | null,
deliveryStatus: string | null,
restaurantName: string,
restaurantSubdomain: string,
restaurantLogoThumbUrl: string | null,
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
notes: string | null,
};
export type OrderPlacedData = {
order: App.Data.OrderData,
confirmationToken: string,
};
export type OrderSummaryData = {
id: number,
number: string,
status: string,
type: string,
customerName: string,
totalCents: number,
placedAt: string | null,
};
export type PaginationMetaData = {
currentPage: number,
lastPage: number,
perPage: number,
total: number,
};
export type PendingInvitationData = {
id: number,
email: string,
expiresAt: string | null,
invitedByName: string | null,
role: App.Enums.RestaurantRole,
};
export type ReorderResultData = {
cart: App.Data.CartData | null,
cartToken: string | null,
skipped: {
name: string,
reason: string,
}[],
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
phoneDisplay: string | null,
phoneHref: string | null,
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
hasAboutSection: boolean,
hasGalleryPhotos: boolean,
createdAt: string | null,
publicUrl: string,
latitude: number | null,
longitude: number | null,
cuisineTags: string[],
marketplaceListed: boolean,
};
export type RestaurantEarningsData = {
restaurantId: number,
name: string,
subdomain: string,
status: string,
month: string,
orders: number,
refundedOrders: number,
foodSubtotalCents: number,
applicationFeeCents: number,
commissionCents: number,
deliveryMarginCents: number,
ledgerCents: number,
feePercent: number,
capCents: number,
capReached: boolean,
capRemainingCents: number | null,
};
export type RestaurantHourData = {
dayOfWeek: number,
opensAt: string,
closesAt: string,
position: number,
};
export type RestaurantImagesData = {
restaurantId: number,
logoUrl: string | null,
logoMediumUrl: string | null,
logoThumbUrl: string | null,
heroImageUrl: string | null,
heroImageMediumUrl: string | null,
aboutImageUrl: string | null,
aboutImageMediumUrl: string | null,
};
export type RestaurantMemberData = {
id: number,
name: string,
email: string,
role: App.Enums.RestaurantRole,
};
export type RestaurantMembershipData = {
isFavorite: boolean,
marketingOptedIn: boolean,
loyaltyPoints: number,
totalOrders: number,
totalSpentCents: number,
lastOrderedAt: string | null,
};
export type RestaurantPhotoData = {
id: number,
caption: string | null,
position: number,
imageUrl: string | null,
imageMediumUrl: string | null,
imageThumbUrl: string | null,
};
export type RestaurantSummaryData = {
id: number,
name: string,
subdomain: string,
description: string | null,
logoThumbUrl: string | null,
logoMediumUrl: string | null,
heroImageMediumUrl: string | null,
city: string | null,
state: string | null,
latitude: number | null,
longitude: number | null,
distanceKm: number | null,
cuisineTags: string[],
isOpen: boolean,
openStatusLabel: string | null,
deliveryEnabled: boolean,
publicUrl: string,
};
export type TwoFactorChallengeData = {
twoFactorRequired: boolean,
challengeToken: string,
};
export type WalletEntryData = {
restaurant: App.Data.RestaurantSummaryData,
loyaltyPoints: number,
pointsPerDollar: number,
totalOrders: number,
totalSpentCents: number,
lastOrderedAt: string | null,
};
}
namespace Enums {
export type ApiKeyScope = '*' | 'restaurants:read' | 'restaurants:write' | 'orders:read' | 'orders:write' | 'menu:read' | 'menu:write' | 'customers:read' | 'api-keys:manage' | 'platform:read';
export type AutoCancelRefundMode = 'auto' | 'manual';
export type CampaignRecipientStatus = 'queued' | 'sent' | 'failed' | 'bounced' | 'complained' | 'unsubscribed';
export type CampaignStatus = 'draft' | 'pending_review' | 'scheduled' | 'sending' | 'sent' | 'cancelled' | 'paused_by_platform';
export type DeliveryFallbackAction = 'try_next_provider' | 'hold_for_owner' | 'auto_cancel_refund';
export type DeliveryFeeStrategy = 'pass_through' | 'absorb';
export type DeliveryIntegrationStatus = 'connected' | 'disconnected' | 'error';
export type DeliveryMode = 'self' | 'third_party';
export type DeliveryProviderName = 'self' | 'doordash' | 'uber';
export type DeliveryStatus = 'pending' | 'driver_assigned' | 'picked_up' | 'delivered' | 'cancelled' | 'failed';
export type EmailSuppressionReason = 'hard_bounce' | 'complaint' | 'manual';
export type MailSender = 'orders' | 'service' | 'support';
export type MarketingChannel = 'email';
export type MarketingConsentAction = 'opted_in' | 'opted_out';
export type MarketingConsentSource = 'checkout' | 'account' | 'unsubscribe_link' | 'admin';
export type MenuImportStatus = 'queued' | 'processing' | 'needs_review' | 'completed' | 'failed';
export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'ready' | 'completed' | 'cancelled';
export type OrderType = 'delivery' | 'pickup';
export type PaymentState = 'captured' | 'authorized' | 'voided';
export type PosIntegrationStatus = 'connected' | 'disconnected' | 'token_expired' | 'error';
export type PosProviderName = 'square' | 'clover';
export type RestaurantImageKind = 'logo' | 'hero' | 'about';
export type RestaurantRole = 'admin' | 'staff';
export type RestaurantStatus = 'pending_review' | 'approved' | 'active' | 'suspended';
export type RevenueRole = 'founder' | 'operator' | 'recruiter' | 'overseer' | 'delivery_margin';
export type SelfDeliveryTipRecipient = 'driver' | 'pool' | 'split_50_50';
export type SocialProvider = 'google' | 'apple';
export type TipRecipient = 'pool' | 'driver' | 'split';
}
}

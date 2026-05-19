declare namespace App {
namespace Data {
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
position: number,
itemTemplateId: number | null,
template: App.Data.ItemTemplateData | null,
defaultSelectionIds: number[],
};
export type RestaurantData = {
id: number,
name: string,
subdomain: string,
description: string | null,
logoUrl: string | null,
logoMediumUrl: string | null,
logoThumbUrl: string | null,
primaryColor: string | null,
secondaryColor: string | null,
email: string | null,
phone: string | null,
taxRatePercent: number,
};
}
namespace Enums {
export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'ready' | 'completed' | 'cancelled';
export type OrderType = 'delivery' | 'pickup';
export type UserRole = 'customer' | 'admin';
}
}

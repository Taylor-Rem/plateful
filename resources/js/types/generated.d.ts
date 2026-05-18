declare namespace App {
namespace Data {
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
modifiers: App.Data.MenuItemModifierData[],
};
export type MenuItemModifierData = {
id: number,
name: string,
groupLabel: string | null,
priceDeltaCents: number,
isDefault: boolean,
position: number,
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
};
}
namespace Enums {
export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'ready' | 'completed' | 'cancelled';
export type OrderType = 'delivery' | 'pickup';
export type UserRole = 'customer' | 'admin';
}
}

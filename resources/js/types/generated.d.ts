declare namespace App {
namespace Data {
export type MenuCategoryData = {
id: number,
name: string,
slug: string,
items: App.Data.MenuItemData[],
};
export type MenuItemData = {
id: number,
name: string,
slug: string,
description: string | null,
priceCents: number,
imageUrl: string | null,
isAvailable: boolean,
modifiers: App.Data.MenuItemModifierData[],
};
export type MenuItemModifierData = {
id: number,
name: string,
groupLabel: string | null,
priceDeltaCents: number,
isDefault: boolean,
};
export type RestaurantData = {
id: number,
name: string,
subdomain: string,
description: string | null,
logoUrl: string | null,
primaryColor: string | null,
secondaryColor: string | null,
};
}
namespace Enums {
export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'ready' | 'completed' | 'cancelled';
export type OrderType = 'delivery' | 'pickup';
export type UserRole = 'customer' | 'admin';
}
}

export type UserFilter = 'all' | 'admins' | 'customers' | 'deleted';

export type UserType = 'super' | 'admin' | 'customer' | 'orphan';

export type UserRestaurant = {
    id: number;
    name: string;
    subdomain: string;
    role: string;
    isSoleAdmin: boolean;
};

export type UserRow = {
    id: number;
    name: string;
    email: string;
    isSuperAdmin: boolean;
    type: UserType;
    restaurants: UserRestaurant[];
    restaurantsCount: number;
    customerRestaurantsCount: number;
    ordersCount: number;
    isDeleted: boolean;
    deletedAt: string | null;
    createdAt: string | null;
    deleteBlockedReason: string | null;
};

export const TYPE_LABELS: Record<UserType, string> = {
    super: 'Super admin',
    admin: 'Restaurant admin',
    customer: 'Customer',
    orphan: 'No access',
};

export const TYPE_BADGE_CLASSES: Record<UserType, string> = {
    super: 'bg-purple-100 text-purple-800 dark:bg-purple-500/15 dark:text-purple-300',
    admin: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    customer:
        'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
    orphan: 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200',
};

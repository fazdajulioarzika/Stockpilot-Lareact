import { Link, usePage } from "@inertiajs/react";
import {
    Boxes,
    History,
    LayoutGrid,
    Package,
    PackageMinus,
    PackagePlus,
    Ruler,
    Tags,
    Truck,
    Warehouse,
    ArrowLeftRight,
    ClipboardCheck,
    type LucideIcon,
} from "lucide-react";

import AppLogo from "@/components/app-logo";
import { NavUser } from "@/components/nav-user";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";

type Item = {
    title: string;
    href: string;
    icon: LucideIcon;
    adminOnly?: boolean;
};
type Group = { label: string; items: Item[] };

const groups: Group[] = [
    {
        label: "Platform",
        items: [{ title: "Dashboard", href: "/dashboard", icon: LayoutGrid }],
    },
    {
        label: "Stok",
        items: [
            { title: "Inventory", href: "/inventory", icon: Boxes },
            {
                title: "Stock Movements",
                href: "/stock-movements",
                icon: History,
            },
        ],
    },
    {
        label: "Transaksi",
        items: [
            {
                title: "Barang Masuk",
                href: "/goods-receipts",
                icon: PackagePlus,
            },
            {
                title: "Barang Keluar",
                href: "/stock-issues",
                icon: PackageMinus,
            },
            { title: "Transfer", href: "/transfers", icon: ArrowLeftRight },
            {
                title: "Stock Opname",
                href: "/stocktakes",
                icon: ClipboardCheck,
            },
        ],
    },

    {
        label: "Master Data",
        items: [
            { title: "Products", href: "/products", icon: Package },
            {
                title: "Categories",
                href: "/categories",
                icon: Tags,
                adminOnly: true,
            },
            { title: "Units", href: "/units", icon: Ruler, adminOnly: true },
            {
                title: "Suppliers",
                href: "/suppliers",
                icon: Truck,
                adminOnly: true,
            },
            {
                title: "Warehouses",
                href: "/warehouses",
                icon: Warehouse,
                adminOnly: true,
            },
        ],
    },
];

export function AppSidebar() {
    const { url, props } = usePage<{
        abilities?: { manageMasterData: boolean } | null;
    }>();
    const isAdmin = props.abilities?.manageMasterData === true;
    const path = url.split("?")[0];

    const isActive = (href: string) =>
        path === href || path.startsWith(`${href}/`);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {groups
                    .map((g) => ({
                        ...g,
                        items: g.items.filter((i) => !i.adminOnly || isAdmin),
                    }))
                    .filter((g) => g.items.length > 0)
                    .map((group) => (
                        <SidebarGroup key={group.label} className="px-2 py-0">
                            <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                            <SidebarMenu>
                                {group.items.map((item) => (
                                    <SidebarMenuItem key={item.href}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isActive(item.href)}
                                            tooltip={{ children: item.title }}
                                        >
                                            <Link href={item.href} prefetch>
                                                <item.icon />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroup>
                    ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

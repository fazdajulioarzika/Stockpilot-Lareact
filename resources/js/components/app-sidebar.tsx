import { Link, usePage } from "@inertiajs/react";
import {
    ArrowLeftRight,
    Boxes,
    ClipboardCheck,
    History,
    LayoutGrid,
    Package,
    PackageMinus,
    PackagePlus,
    Ruler,
    ScrollText,
    Tags,
    Truck,
    Users,
    Warehouse,
    Coins,
    FileSpreadsheet,
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

type Abilities = {
    manageMasterData: boolean;
    viewAudit: boolean;
    manageUsers: boolean;
    viewValuation: boolean;
};

type Item = {
    title: string;
    href: string;
    icon: LucideIcon;
    ability?: keyof Abilities;
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
            {
                title: "Nilai Persediaan",
                href: "/valuation",
                icon: Coins,
                ability: "viewValuation",
            },
            { title: "Laporan", href: "/reports", icon: FileSpreadsheet },
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
                ability: "manageMasterData",
            },
            {
                title: "Units",
                href: "/units",
                icon: Ruler,
                ability: "manageMasterData",
            },
            {
                title: "Suppliers",
                href: "/suppliers",
                icon: Truck,
                ability: "manageMasterData",
            },
            {
                title: "Warehouses",
                href: "/warehouses",
                icon: Warehouse,
                ability: "manageMasterData",
            },
        ],
    },
    {
        label: "Administrasi",
        items: [
            {
                title: "Users",
                href: "/users",
                icon: Users,
                ability: "manageUsers",
            },
            {
                title: "Audit Log",
                href: "/audit-logs",
                icon: ScrollText,
                ability: "viewAudit",
            },
        ],
    },
];

export function AppSidebar() {
    const { url, props } = usePage<{ abilities?: Abilities | null }>();
    const abilities = props.abilities;
    const path = url.split("?")[0];

    const isActive = (href: string) =>
        path === href || path.startsWith(`${href}/`);
    const allowed = (item: Item) =>
        !item.ability || abilities?.[item.ability] === true;

    const visible = groups
        .map((g) => ({ ...g, items: g.items.filter(allowed) }))
        .filter((g) => g.items.length > 0);

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
                {visible.map((group) => (
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

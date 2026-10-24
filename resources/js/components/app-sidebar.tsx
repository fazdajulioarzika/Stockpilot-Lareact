import { Link } from "@inertiajs/react";
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    Ruler,
    Tags,
    Package,
    Truck,
    Warehouse,
    PackagePlus,
    PackageMinus,
    Boxes,
    History,
} from "lucide-react";
import AppLogo from "@/components/app-logo";
import { NavFooter } from "@/components/nav-footer";
import { NavMain } from "@/components/nav-main";
import { NavUser } from "@/components/nav-user";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { dashboard } from "@/routes";
import type { NavItem } from "@/types";

const mainNavItems: NavItem[] = [
    {
        title: "Dashboard",
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: "Categories",
        href: "/categories",
        icon: Tags,
    },
    {
        title: "Units",
        href: "/units",
        icon: Ruler,
    },
    {
        title: "Products",
        href: "/products",
        icon: Package,
    },
    { title: "Suppliers", href: "/suppliers", icon: Truck },
    { title: "Warehouses", href: "/warehouses", icon: Warehouse },
    { title: "Inventory", href: "/inventory", icon: Boxes },
    { title: "Stock Movements", href: "/stock-movements", icon: History },
    { title: "Barang Masuk", href: "/goods-receipts", icon: PackagePlus },
    { title: "Barang Keluar", href: "/stock-issues", icon: PackageMinus },
];

const footerNavItems: NavItem[] = [
    {
        title: "Repository",
        href: "https://github.com/laravel/react-starter-kit",
        icon: FolderGit2,
    },
    {
        title: "Documentation",
        href: "https://laravel.com/docs/starter-kits#react",
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

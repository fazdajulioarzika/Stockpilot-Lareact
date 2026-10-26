import { Head, Link, router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { useState } from "react";

import {
    PO_STATUS_LABEL,
    PurchaseOrderStatusBadge,
} from "@/components/purchase-orders/status-badge";
import {
    formatDateTime,
    formatMoney,
    Paginated,
    WarehouseOption,
} from "@/components/stock-documents/types";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Row = {
    id: number;
    number: string;
    status: string;
    created_at: string;
    items_count: number;
    total_value: string;
    supplier: { name: string };
    warehouse: { code: string };
    creator: { name: string };
};

type Filters = { search: string; status: string; warehouse_id: string };

type Props = {
    orders: Paginated<Row>;
    warehouses: WarehouseOption[];
    filters: Filters;
    can: { create: boolean };
};

export default function PurchaseOrdersIndex({
    orders,
    warehouses,
    filters,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/purchase-orders",
            {
                search: m.search || undefined,
                status: m.status !== "all" ? m.status : undefined,
                warehouse_id:
                    m.warehouse_id !== "all" ? m.warehouse_id : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title="Purchase Order" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilters({ search });
                            }}
                            className="flex gap-2"
                        >
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nomor, referensi, supplier..."
                                className="w-64"
                            />
                            <Button type="submit" variant="secondary">
                                Cari
                            </Button>
                        </form>

                        <Select
                            value={filters.warehouse_id}
                            onValueChange={(v) =>
                                applyFilters({ warehouse_id: v })
                            }
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Gudang" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Semua gudang
                                </SelectItem>
                                {warehouses.map((w) => (
                                    <SelectItem key={w.id} value={String(w.id)}>
                                        {w.code} - {w.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.status}
                            onValueChange={(v) => applyFilters({ status: v })}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Semua status
                                </SelectItem>
                                {Object.entries(PO_STATUS_LABEL).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    )
                                )}
                            </SelectContent>
                        </Select>
                    </div>

                    {can.create && (
                        <Button asChild>
                            <Link href="/purchase-orders/create">
                                <Plus className="mr-1 size-4" /> Buat Purchase
                                Order
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Gudang</TableHead>
                                <TableHead className="text-right">
                                    Item
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Tanggal</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada Purchase Order.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                orders.data.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={`/purchase-orders/${o.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {o.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{o.supplier.name}</TableCell>
                                        <TableCell>
                                            {o.warehouse.code}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {o.items_count}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(o.total_value)}
                                        </TableCell>
                                        <TableCell>
                                            <PurchaseOrderStatusBadge
                                                status={o.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {formatDateTime(o.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {orders.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {orders.total} Purchase Order
                        </span>
                        <div className="flex gap-1">
                            {orders.links.map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? "default" : "outline"
                                    }
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true }
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: "Purchase Order", href: "/purchase-orders" }],
};

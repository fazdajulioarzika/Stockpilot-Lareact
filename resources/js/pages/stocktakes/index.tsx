import { Head, Link, router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { useState } from "react";

import {
    formatDateTime,
    Paginated,
    WarehouseOption,
} from "@/components/stock-documents/types";
import {
    STOCKTAKE_STATUS_LABEL,
    StocktakeStatusBadge,
} from "@/components/stocktakes/status-badge";
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
    warehouse: { code: string; name: string };
    creator: { name: string };
};

type Filters = { search: string; status: string; warehouse_id: string };

type Props = {
    stocktakes: Paginated<Row>;
    warehouses: WarehouseOption[];
    filters: Filters;
    can: { create: boolean };
};

export default function StocktakesIndex({
    stocktakes,
    warehouses,
    filters,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/stocktakes",
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
            <Head title="Stock Opname" />

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
                                placeholder="Cari nomor..."
                                className="w-56"
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
                                {Object.entries(STOCKTAKE_STATUS_LABEL).map(
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
                            <Link href="/stocktakes/create">
                                <Plus className="mr-1 size-4" /> Buat Opname
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Gudang</TableHead>
                                <TableHead className="text-right">
                                    Item
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Dibuat oleh</TableHead>
                                <TableHead>Tanggal</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stocktakes.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada opname.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                stocktakes.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={`/stocktakes/${s.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {s.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {s.warehouse.code}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {s.items_count}
                                        </TableCell>
                                        <TableCell>
                                            <StocktakeStatusBadge
                                                status={s.status}
                                            />
                                        </TableCell>
                                        <TableCell>{s.creator.name}</TableCell>
                                        <TableCell>
                                            {formatDateTime(s.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {stocktakes.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {stocktakes.total} opname
                        </span>
                        <div className="flex gap-1">
                            {stocktakes.links.map((link, i) => (
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

StocktakesIndex.layout = {
    breadcrumbs: [{ title: "Stock Opname", href: "/stocktakes" }],
};

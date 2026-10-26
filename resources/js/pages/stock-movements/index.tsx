import { Head, Link, router } from "@inertiajs/react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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

type Movement = {
    id: number;
    created_at: string;
    movement_type: string;
    quantity_delta: string;
    reference_url: string | null;
    warehouse: { code: string };
    product: { sku: string; name: string };
    creator: { name: string };
};

type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = {
    warehouse_id: string;
    product_id: string;
    type: string;
    from: string;
    to: string;
};

type Props = {
    movements: Paginated<Movement>;
    warehouses: { id: number; code: string; name: string }[];
    products: { id: number; sku: string; name: string }[];
    types: string[];
    filters: Filters;
};

const TYPE_LABEL: Record<string, string> = {
    receipt: "Barang masuk",
    issue: "Barang keluar",
    transfer_out: "Transfer keluar",
    transfer_in: "Transfer masuk",
    adjustment: "Penyesuaian",
    reversal: "Reversal",
    transfer_return: "Retur transfer",
    revaluation: "Penilaian ulang",
};

export default function StockMovementsIndex({
    movements,
    warehouses,
    products,
    types,
    filters,
}: Props) {
    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, ...next };
        router.get(
            "/stock-movements",
            {
                warehouse_id:
                    m.warehouse_id !== "all" ? m.warehouse_id : undefined,
                product_id: m.product_id !== "all" ? m.product_id : undefined,
                type: m.type !== "all" ? m.type : undefined,
                from: m.from || undefined,
                to: m.to || undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title="Stock Movements" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Select
                        value={filters.warehouse_id}
                        onValueChange={(v) => applyFilters({ warehouse_id: v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Gudang" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua gudang</SelectItem>
                            {warehouses.map((w) => (
                                <SelectItem key={w.id} value={String(w.id)}>
                                    {w.code}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.product_id}
                        onValueChange={(v) => applyFilters({ product_id: v })}
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="Produk" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua produk</SelectItem>
                            {products.map((p) => (
                                <SelectItem key={p.id} value={String(p.id)}>
                                    {p.sku} - {p.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.type}
                        onValueChange={(v) => applyFilters({ type: v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Tipe" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua tipe</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {TYPE_LABEL[t] ?? t}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="grid gap-1">
                        <Label
                            htmlFor="from"
                            className="text-xs text-muted-foreground"
                        >
                            Dari
                        </Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from}
                            onChange={(e) =>
                                applyFilters({ from: e.target.value })
                            }
                            className="w-40"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label
                            htmlFor="to"
                            className="text-xs text-muted-foreground"
                        >
                            Sampai
                        </Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to}
                            onChange={(e) =>
                                applyFilters({ to: e.target.value })
                            }
                            className="w-40"
                        />
                    </div>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Gudang</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead>Tipe</TableHead>
                                <TableHead className="text-right">
                                    Perubahan
                                </TableHead>
                                <TableHead>Oleh</TableHead>
                                <TableHead>Dokumen</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada mutasi.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                movements.data.map((m) => {
                                    const delta = Number(m.quantity_delta);

                                    return (
                                        <TableRow key={m.id}>
                                            <TableCell>
                                                {new Date(
                                                    m.created_at
                                                ).toLocaleString("id-ID", {
                                                    day: "2-digit",
                                                    month: "short",
                                                    year: "numeric",
                                                    hour: "2-digit",
                                                    minute: "2-digit",
                                                })}
                                            </TableCell>
                                            <TableCell>
                                                {m.warehouse.code}
                                            </TableCell>
                                            <TableCell>
                                                <span className="font-mono text-xs">
                                                    {m.product.sku}
                                                </span>{" "}
                                                {m.product.name}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {TYPE_LABEL[
                                                        m.movement_type
                                                    ] ?? m.movement_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell
                                                className={`text-right font-medium ${
                                                    delta < 0
                                                        ? "text-destructive"
                                                        : ""
                                                }`}
                                            >
                                                {delta > 0 ? "+" : ""}
                                                {delta.toLocaleString("id-ID", {
                                                    maximumFractionDigits: 3,
                                                })}
                                            </TableCell>
                                            <TableCell>
                                                {m.creator.name}
                                            </TableCell>
                                            <TableCell>
                                                {m.reference_url ? (
                                                    <Link
                                                        href={m.reference_url}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        Lihat
                                                    </Link>
                                                ) : (
                                                    "-"
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>

                {movements.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {movements.total} mutasi
                        </span>
                        <div className="flex gap-1">
                            {movements.links.map((link, i) => (
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

StockMovementsIndex.layout = {
    breadcrumbs: [{ title: "Stock Movements", href: "/stock-movements" }],
};

import { Head, Link, router } from "@inertiajs/react";
import { ArrowRight, Plus } from "lucide-react";
import { useState } from "react";

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
import {
    formatDateTime,
    Paginated,
    WarehouseOption,
} from "@/components/stock-documents/types";

import {
    TransferStatusBadge,
    TRANSFER_STATUS_LABEL,
} from "@/components/status-badge";

type Row = {
    id: number;
    number: string;
    reference: string | null;
    status: string;
    created_at: string;
    items_count: number;
    source: { code: string };
    destination: { code: string };
    creator: { name: string };
};

type Filters = { search: string; status: string; warehouse_id: string };

type Props = {
    transfers: Paginated<Row>;
    warehouses: WarehouseOption[];
    filters: Filters;
    can: { create: boolean };
};

export default function TransfersIndex({
    transfers,
    warehouses,
    filters,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/transfers",
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
            <Head title="Transfer" />

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
                                placeholder="Cari nomor atau referensi..."
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
                                {Object.entries(TRANSFER_STATUS_LABEL).map(
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
                            <Link href="/transfers/create">
                                <Plus className="mr-1 size-4" /> Buat Transfer
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Rute</TableHead>
                                <TableHead>Referensi</TableHead>
                                <TableHead className="text-right">
                                    Item
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Dibuat oleh</TableHead>
                                <TableHead>Tanggal</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transfers.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada transfer.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                transfers.data.map((t) => (
                                    <TableRow key={t.id}>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={`/transfers/${t.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {t.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1">
                                                {t.source.code}
                                                <ArrowRight className="size-3 text-muted-foreground" />
                                                {t.destination.code}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {t.reference ?? "-"}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {t.items_count}
                                        </TableCell>
                                        <TableCell>
                                            <TransferStatusBadge
                                                status={t.status}
                                            />
                                        </TableCell>
                                        <TableCell>{t.creator.name}</TableCell>
                                        <TableCell>
                                            {formatDateTime(t.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {transfers.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {transfers.total} transfer
                        </span>
                        <div className="flex gap-1">
                            {transfers.links.map((link, i) => (
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

TransfersIndex.layout = {
    breadcrumbs: [{ title: "Transfer", href: "/transfers" }],
};

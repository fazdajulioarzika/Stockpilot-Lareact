import { Head, Link, router } from "@inertiajs/react";
import { Plus } from "lucide-react";
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

import { StatusBadge } from "./status-badge";
import {
    DocumentMeta,
    formatDateTime,
    Paginated,
    WarehouseOption,
} from "./types";

type DocumentRow = {
    id: number;
    number: string;
    reference: string | null;
    status: string;
    created_at: string;
    items_count: number;
    warehouse: { code: string; name: string };
    creator: { name: string };
};

type Filters = { search: string; status: string; warehouse_id: string };

export type IndexProps = {
    meta: DocumentMeta;
    documents: Paginated<DocumentRow>;
    warehouses: WarehouseOption[];
    filters: Filters;
    can: { create: boolean };
};

export default function IndexView({
    meta,
    documents,
    warehouses,
    filters,
    can,
}: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const merged = { ...filters, search, ...next };
        router.get(
            meta.basePath,
            {
                search: merged.search || undefined,
                status: merged.status !== "all" ? merged.status : undefined,
                warehouse_id:
                    merged.warehouse_id !== "all"
                        ? merged.warehouse_id
                        : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title={meta.title} />

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
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Semua status
                                </SelectItem>
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="posted">
                                    Diposting
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {can.create && (
                        <Button asChild>
                            <Link href={`${meta.basePath}/create`}>
                                <Plus className="mr-1 size-4" /> Buat{" "}
                                {meta.title}
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
                            {documents.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada dokumen.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                documents.data.map((d) => (
                                    <TableRow key={d.id}>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={`${meta.basePath}/${d.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {d.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {d.warehouse.code}
                                        </TableCell>
                                        <TableCell>
                                            {d.reference ?? "-"}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {d.items_count}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={d.status} />
                                        </TableCell>
                                        <TableCell>{d.creator.name}</TableCell>
                                        <TableCell>
                                            {formatDateTime(d.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {documents.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {documents.total} dokumen
                        </span>
                        <div className="flex gap-1">
                            {documents.links.map((link, i) => (
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

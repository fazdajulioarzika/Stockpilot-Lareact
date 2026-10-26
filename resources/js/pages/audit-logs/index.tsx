import { Head, Link, router } from "@inertiajs/react";

import { formatDateTime, Paginated } from "@/components/stock-documents/types";
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

type Log = {
    id: number;
    created_at: string;
    action: string;
    user: string | null;
    metadata: Record<string, unknown>;
    entity_label: string;
    entity_url: string | null;
};

type Filters = { action: string; user_id: string; from: string; to: string };

type Props = {
    logs: Paginated<Log>;
    actions: string[];
    users: { id: number; name: string }[];
    filters: Filters;
};

const ACTION_LABEL: Record<string, string> = {
    "goods_receipt.posted": "Barang masuk diposting",
    "stock_issue.posted": "Barang keluar diposting",
    "transfer.submitted": "Transfer diajukan",
    "transfer.approved": "Transfer disetujui",
    "transfer.dispatched": "Transfer dikirim",
    "transfer.received": "Transfer diterima",
    "transfer.remainder_returned": "Sisa transfer diretur",
    "transfer.cancelled": "Transfer dibatalkan",
    "stocktake.started": "Opname dimulai",
    "stocktake.submitted": "Opname diajukan",
    "stocktake.rejected": "Opname ditolak",
    "stocktake.posted": "Opname diposting",
    "stocktake.cancelled": "Opname dibatalkan",
    "user.created": "Pengguna dibuat",
    "user.updated": "Pengguna diubah",
    "goods_receipt.reversed": "Barang masuk dibalik",
    "stock_issue.reversed": "Barang keluar dibalik",
    "stock.revalued": "Stok dinilai ulang",
    "report.exported": "Laporan diunduh",
    "purchase_order.submitted": "PO diajukan",
    "purchase_order.approved": "PO disetujui",
    "purchase_order.cancelled": "PO dibatalkan",
    "purchase_order.closed": "PO ditutup",
};

function summary(metadata: Record<string, unknown>): string {
    const parts = Object.entries(metadata).map(([key, value]) => {
        const text =
            typeof value === "object" && value !== null
                ? JSON.stringify(value)
                : String(value);

        return `${key}: ${text}`;
    });

    return parts.length ? parts.join(" · ") : "-";
}

export default function AuditLogsIndex({
    logs,
    actions,
    users,
    filters,
}: Props) {
    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, ...next };
        router.get(
            "/audit-logs",
            {
                action: m.action !== "all" ? m.action : undefined,
                user_id: m.user_id !== "all" ? m.user_id : undefined,
                from: m.from || undefined,
                to: m.to || undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title="Audit Log" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Select
                        value={filters.action}
                        onValueChange={(v) => applyFilters({ action: v })}
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="Aksi" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua aksi</SelectItem>
                            {actions.map((a) => (
                                <SelectItem key={a} value={a}>
                                    {ACTION_LABEL[a] ?? a}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.user_id}
                        onValueChange={(v) => applyFilters({ user_id: v })}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Pengguna" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua pengguna</SelectItem>
                            {users.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)}>
                                    {u.name}
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
                                <TableHead>Pengguna</TableHead>
                                <TableHead>Aksi</TableHead>
                                <TableHead>Objek</TableHead>
                                <TableHead>Detail</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada aktivitas tercatat.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                logs.data.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell>
                                            {formatDateTime(l.created_at)}
                                        </TableCell>
                                        <TableCell>{l.user ?? "-"}</TableCell>
                                        <TableCell className="font-medium">
                                            {ACTION_LABEL[l.action] ?? l.action}
                                        </TableCell>
                                        <TableCell>
                                            {l.entity_url ? (
                                                <Link
                                                    href={l.entity_url}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {l.entity_label}
                                                </Link>
                                            ) : (
                                                l.entity_label
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-md whitespace-normal text-xs text-muted-foreground">
                                            {summary(l.metadata)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {logs.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {logs.total} aktivitas
                        </span>
                        <div className="flex gap-1">
                            {logs.links.map((link, i) => (
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

AuditLogsIndex.layout = {
    breadcrumbs: [{ title: "Audit Log", href: "/audit-logs" }],
};

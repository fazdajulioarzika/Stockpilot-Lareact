import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus } from "lucide-react";
import { FormEvent, useState } from "react";

import { Paginated } from "@/components/stock-documents/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
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

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    is_self: boolean;
    warehouses: string[];
    warehouse_ids: number[];
};

type Filters = { search: string; role: string };

type Props = {
    users: Paginated<UserRow>;
    warehouses: {
        id: number;
        code: string;
        name: string;
        is_active: boolean;
    }[];
    roles: { value: string; label: string }[];
    filters: Filters;
};

type FormData = {
    name: string;
    email: string;
    password: string;
    role: string;
    is_active: boolean;
    warehouse_ids: number[];
};

const emptyForm: FormData = {
    name: "",
    email: "",
    password: "",
    role: "warehouse_staff",
    is_active: true,
    warehouse_ids: [],
};

export default function UsersIndex({
    users,
    warehouses,
    roles,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<UserRow | null>(null);

    const form = useForm<FormData>(emptyForm);
    const errors = form.errors as Record<string, string | undefined>;
    const roleLabel = (value: string) =>
        roles.find((r) => r.value === value)?.label ?? value;
    const globalAccess =
        form.data.role === "admin" || form.data.role === "manager";

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/users",
            {
                search: m.search || undefined,
                role: m.role !== "all" ? m.role : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    function openCreate() {
        setEditing(null);
        form.setData({ ...emptyForm, warehouse_ids: [] });
        form.clearErrors();
        setFormOpen(true);
    }

    function openEdit(user: UserRow) {
        setEditing(user);
        form.setData({
            name: user.name,
            email: user.email,
            password: "",
            role: user.role,
            is_active: user.is_active,
            warehouse_ids: [...user.warehouse_ids],
        });
        form.clearErrors();
        setFormOpen(true);
    }

    function toggleWarehouse(id: number, checked: boolean) {
        form.setData(
            "warehouse_ids",
            checked
                ? [...form.data.warehouse_ids, id]
                : form.data.warehouse_ids.filter((w) => w !== id)
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        };

        if (editing) {
            form.put(`/users/${editing.id}`, options);
        } else {
            form.post("/users", options);
        }
    }

    return (
        <>
            <Head title="Users" />

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
                                placeholder="Cari nama atau email..."
                                className="w-64"
                            />
                            <Button type="submit" variant="secondary">
                                Cari
                            </Button>
                        </form>

                        <Select
                            value={filters.role}
                            onValueChange={(v) => applyFilters({ role: v })}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua role</SelectItem>
                                {roles.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <Button onClick={openCreate}>
                        <Plus className="mr-1 size-4" /> Tambah Pengguna
                    </Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Gudang</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-20 text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Tidak ada pengguna.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                users.data.map((u) => (
                                    <TableRow key={u.id}>
                                        <TableCell className="font-medium">
                                            {u.name}
                                            {u.is_self && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    (Anda)
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{u.email}</TableCell>
                                        <TableCell>
                                            {roleLabel(u.role)}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            {u.role === "admin" ||
                                            u.role === "manager"
                                                ? "Semua gudang"
                                                : u.warehouses.length
                                                ? u.warehouses.join(", ")
                                                : "-"}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    u.is_active
                                                        ? "default"
                                                        : "secondary"
                                                }
                                            >
                                                {u.is_active
                                                    ? "Aktif"
                                                    : "Nonaktif"}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${u.name}`}
                                                onClick={() => openEdit(u)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {users.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {users.total} pengguna
                        </span>
                        <div className="flex gap-1">
                            {users.links.map((link, i) => (
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

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>
                                {editing ? "Ubah Pengguna" : "Tambah Pengguna"}
                            </DialogTitle>
                            <DialogDescription>
                                Akun tidak dihapus, hanya dinonaktifkan, supaya
                                riwayat transaksi tetap utuh.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData("name", e.target.value)
                                    }
                                    autoFocus
                                />
                                {errors.name && (
                                    <p className="text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData("email", e.target.value)
                                    }
                                />
                                {errors.email && (
                                    <p className="text-sm text-destructive">
                                        {errors.email}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {editing
                                        ? "Password baru (kosongkan jika tidak diubah)"
                                        : "Password"}
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(e) =>
                                        form.setData("password", e.target.value)
                                    }
                                />
                                {errors.password && (
                                    <p className="text-sm text-destructive">
                                        {errors.password}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label>Role</Label>
                                <Select
                                    value={form.data.role}
                                    onValueChange={(v) =>
                                        form.setData("role", v)
                                    }
                                    disabled={editing?.is_self}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((r) => (
                                            <SelectItem
                                                key={r.value}
                                                value={r.value}
                                            >
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.role && (
                                    <p className="text-sm text-destructive">
                                        {errors.role}
                                    </p>
                                )}
                                {editing?.is_self && (
                                    <p className="text-xs text-muted-foreground">
                                        Anda tidak dapat mengubah role atau
                                        menonaktifkan akun sendiri.
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label>Gudang yang ditugaskan</Label>
                                {globalAccess && (
                                    <p className="text-xs text-muted-foreground">
                                        Admin dan Manager mengakses semua
                                        gudang, penugasan di bawah tidak
                                        dipakai.
                                    </p>
                                )}
                                <div className="grid gap-2 rounded-md border p-3">
                                    {warehouses.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Belum ada gudang.
                                        </p>
                                    )}
                                    {warehouses.map((w) => (
                                        <div
                                            key={w.id}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`wh-${w.id}`}
                                                checked={form.data.warehouse_ids.includes(
                                                    w.id
                                                )}
                                                onCheckedChange={(c) =>
                                                    toggleWarehouse(
                                                        w.id,
                                                        c === true
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`wh-${w.id}`}
                                                className="font-normal"
                                            >
                                                {w.code} - {w.name}
                                                {!w.is_active && " (nonaktif)"}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                                {errors.warehouse_ids && (
                                    <p className="text-sm text-destructive">
                                        {errors.warehouse_ids}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    checked={form.data.is_active}
                                    disabled={editing?.is_self}
                                    onCheckedChange={(c) =>
                                        form.setData("is_active", c === true)
                                    }
                                />
                                <Label htmlFor="is_active">Akun aktif</Label>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setFormOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? "Menyimpan..." : "Simpan"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: "Users", href: "/users" }],
};

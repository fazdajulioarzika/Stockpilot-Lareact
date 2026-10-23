import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { FormEvent, useState } from "react";

import { Button } from "@/components/ui/button";
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Category = {
    id: number;
    name: string;
    slug: string;
    products_count: number;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Props = {
    categories: Paginated<Category>;
    filters: { search: string };
};

export default function CategoriesIndex({ categories, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Category | null>(null);
    const [deleting, setDeleting] = useState<Category | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const form = useForm({ name: "" });

    function openCreate() {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setFormOpen(true);
    }

    function openEdit(category: Category) {
        setEditing(category);
        form.setData("name", category.name);
        form.clearErrors();
        setFormOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        };

        if (editing) {
            form.put(`/categories/${editing.id}`, options);
        } else {
            form.post("/categories", options);
        }
    }

    function applySearch(e: FormEvent) {
        e.preventDefault();
        router.get(
            "/categories",
            { search: search || undefined },
            { preserveState: true, replace: true }
        );
    }

    function confirmDelete() {
        if (!deleting) return;
        setDeleteProcessing(true);
        router.delete(`/categories/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteProcessing(false);
                setDeleting(null);
            },
        });
    }

    return (
        <>
            <Head title="Categories" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={applySearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari kategori..."
                            className="w-64"
                        />
                        <Button type="submit" variant="secondary">
                            Cari
                        </Button>
                    </form>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1 size-4" /> Tambah Kategori
                    </Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Slug</TableHead>
                                <TableHead className="text-right">
                                    Produk
                                </TableHead>
                                <TableHead className="w-28 text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada kategori.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                categories.data.map((category) => (
                                    <TableRow key={category.id}>
                                        <TableCell className="font-medium">
                                            {category.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {category.slug}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {category.products_count}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${category.name}`}
                                                onClick={() =>
                                                    openEdit(category)
                                                }
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Hapus ${category.name}`}
                                                onClick={() =>
                                                    setDeleting(category)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {categories.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {categories.total} kategori
                        </span>
                        <div className="flex gap-1">
                            {categories.links.map((link, i) => (
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

            {/* Dialog tambah/ubah */}
            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>
                                {editing ? "Ubah Kategori" : "Tambah Kategori"}
                            </DialogTitle>
                            <DialogDescription>
                                Nama kategori harus unik.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 grid gap-2">
                            <Label htmlFor="name">Nama kategori</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData("name", e.target.value)
                                }
                                autoFocus
                            />
                            {form.errors.name && (
                                <p className="text-sm text-destructive">
                                    {form.errors.name}
                                </p>
                            )}
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

            {/* Dialog konfirmasi hapus */}
            <Dialog
                open={!!deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus kategori?</DialogTitle>
                        <DialogDescription>
                            Kategori "{deleting?.name}" akan dihapus. Tindakan
                            ini tidak dapat dibatalkan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDelete}
                            disabled={deleteProcessing}
                        >
                            {deleteProcessing ? "Menghapus..." : "Hapus"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
CategoriesIndex.layout = {
    breadcrumbs: [{ title: "Categories", href: "/categories" }],
};

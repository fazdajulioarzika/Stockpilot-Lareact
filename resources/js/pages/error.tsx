import { Head, Link } from "@inertiajs/react";

import { Button } from "@/components/ui/button";

const MESSAGES: Record<number, { title: string; text: string }> = {
    403: {
        title: "Akses ditolak",
        text: "Anda tidak memiliki izin untuk membuka halaman atau melakukan tindakan ini. Hubungi Admin jika Anda merasa ini keliru.",
    },
    404: {
        title: "Data tidak ditemukan",
        text: "Halaman atau data yang Anda cari tidak ada atau sudah dihapus.",
    },
    500: {
        title: "Terjadi kesalahan",
        text: "Ada masalah di server. Data Anda aman, silakan coba lagi beberapa saat lagi.",
    },
    503: {
        title: "Sedang pemeliharaan",
        text: "Aplikasi sedang dalam pemeliharaan. Silakan coba lagi sebentar lagi.",
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const message = MESSAGES[status] ?? {
        title: "Terjadi kesalahan",
        text: "Silakan coba lagi.",
    };

    return (
        <>
            <Head title={message.title} />

            <div className="flex flex-col items-start gap-4 p-4">
                <p className="text-sm font-medium text-muted-foreground">
                    Kode {status}
                </p>
                <h1 className="text-2xl font-semibold">{message.title}</h1>
                <p className="max-w-xl text-sm text-muted-foreground">
                    {message.text}
                </p>
                <div className="flex gap-2">
                    <Button asChild>
                        <Link href="/dashboard">Ke Dashboard</Link>
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => window.history.back()}
                    >
                        Kembali
                    </Button>
                </div>
            </div>
        </>
    );
}

ErrorPage.layout = {
    breadcrumbs: [{ title: "Error", href: "#" }],
};

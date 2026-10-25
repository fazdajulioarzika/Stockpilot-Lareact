import { Link, usePage } from "@inertiajs/react";
import { Lock } from "lucide-react";

type LockInfo = { id: number; number: string; code: string };

// Halaman tempat banner berguna: transaksi yang ditolak saat gudang terkunci.
const WATCHED = [
    "/goods-receipts",
    "/stock-issues",
    "/transfers",
    "/stocktakes",
];

export function WarehouseLockBanner() {
    const { url, props } = usePage<{ lockedWarehouses?: LockInfo[] }>();
    const locks = props.lockedWarehouses ?? [];
    const path = url.split("?")[0];

    if (
        locks.length === 0 ||
        !WATCHED.some((p) => path === p || path.startsWith(`${p}/`))
    ) {
        return null;
    }

    return (
        <div
            role="alert"
            className="mx-4 mt-4 flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <Lock className="mt-0.5 size-4 shrink-0" />
            <p>
                Gudang sedang dalam stock opname, transaksi stok di sana ditolak
                sampai opname selesai:{" "}
                {locks.map((l, i) => (
                    <span key={l.id}>
                        {i > 0 && ", "}
                        <Link
                            href={`/stocktakes/${l.id}`}
                            className="font-medium underline underline-offset-4"
                        >
                            {l.code} ({l.number})
                        </Link>
                    </span>
                ))}
                .
            </p>
        </div>
    );
}

export type DocumentMeta = {
    title: string;
    basePath: string;
    route: string;
    view: string;
    type: "receipt" | "issue";
};

export type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type WarehouseOption = { id: number; code: string; name: string };

export function formatDateTime(value: string | null): string {
    if (!value) return "-";

    return new Date(value).toLocaleString("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

export function formatQty(value: string | number): string {
    return Number(value).toLocaleString("id-ID", { maximumFractionDigits: 3 });
}

import { Download } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

type Value = string | number | boolean | null | undefined;

export function exportUrl(
    path: string,
    params: Record<string, Value>,
    format: "id" | "intl"
): string {
    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (
            value === undefined ||
            value === null ||
            value === "" ||
            value === "all" ||
            value === false
        )
            return;

        query.set(key, value === true ? "1" : String(value));
    });

    if (format === "intl") query.set("format", "intl");

    const qs = query.toString();

    return qs ? `${path}?${qs}` : path;
}

type Props = { path: string; params: Record<string, Value> };

export function ExportButtons({ path, params }: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    <Download className="mr-1 size-4" /> Unduh CSV
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <a href={exportUrl(path, params, "id")}>
                        Excel Indonesia (titik koma, desimal koma)
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={exportUrl(path, params, "intl")}>
                        Internasional (koma, desimal titik)
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

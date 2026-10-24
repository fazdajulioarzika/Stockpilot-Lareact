import ShowView, {
    type ShowProps,
} from "@/components/stock-documents/show-view";

export default function GoodsReceiptsShow(props: ShowProps) {
    return <ShowView {...props} />;
}

GoodsReceiptsShow.layout = {
    breadcrumbs: [
        { title: "Barang Masuk", href: "/goods-receipts" },
        { title: "Detail", href: "#" },
    ],
};

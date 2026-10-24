import IndexView, {
    type IndexProps,
} from "@/components/stock-documents/index-view";

export default function GoodsReceiptsIndex(props: IndexProps) {
    return <IndexView {...props} />;
}

GoodsReceiptsIndex.layout = {
    breadcrumbs: [{ title: "Barang Masuk", href: "/goods-receipts" }],
};

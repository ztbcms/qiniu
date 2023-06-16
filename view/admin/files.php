<div id="app" v-cloak>
    <el-card>
        <div>
            <el-form :inline="true" :model="searchForm">
                <el-form-item label="">
                    <el-date-picker
                            v-model="searchForm.datetime"
                            type="daterange"
                            range-separator="至"
                            value-format="yyyy-MM-dd"
                            start-placeholder="开始日期"
                            end-placeholder="结束日期">
                    </el-date-picker>
                </el-form-item>
                <el-form-item label="">
                    <el-input v-model="searchForm.file_name" placeholder="文件名" style="width: 220px"></el-input>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="search">查询</el-button>
                </el-form-item>
            </el-form>
        </div>
        <el-table
                :data="lists"
                style="width: 100%"
                @sort-change="onSortChange"
        >
            <el-table-column
                    min-width="180"
                    align="left"
                    prop="file_name"
                    label="文件名"
            >
            </el-table-column>
            <el-table-column
                    align="center"
                    prop="uuid"
                    label="UUID"
                    width="280">
            </el-table-column>
            <el-table-column
                    min-width="100"
                    align="center"
                    prop="bucket"
                    label="bucket">
            </el-table-column>
            <el-table-column
                    align="center"
                    width="100"
                    prop="download_amount"
                    sortable="custom"
                    label="下载量">
            </el-table-column>
            <el-table-column
                    align="center"
                    prop="view_amount"
                    width="100"
                    sortable="custom"
                    label="浏览量">
            </el-table-column>
            <el-table-column
                    min-width="80"
                    align="center"
                    prop="is_block"
                    label="是否禁用">
                <template slot-scope="scope">
                    <template v-if="scope.row.file_status == 0">
                        <span>启用中</span>
                        <el-button type="danger" size="mini" @click="changeFileStatus(scope.row, 1)">禁用</el-button>
                    </template>
                    <template v-else>
                        <span style="color: red">禁用中</span>
                        <el-button type="primary" size="mini" @click="changeFileStatus(scope.row, 0)">启用</el-button>
                    </template>
                </template>

            </el-table-column>
            <el-table-column
                    min-width="80"
                    align="center"
                    prop="create_time"
                    label="创建时间">
                </el-switch>
            </el-table-column>
            <el-table-column
                    min-width="100"
                    align="center"
                    prop="is_block"
                    label="操作">
                <template slot-scope="scope">
                    <el-button type="primary" size="mini" @click="viewFile(scope.row)" style="margin: 4px">查看
                    </el-button>
                    <el-button type="danger" size="mini" @click="deleteFile(scope.row)" style="margin: 4px">删除
                    </el-button>
                </template>

            </el-table-column>
        </el-table>
        <div style="margin-top: 20px">
            <el-pagination
                    background
                    @current-change="currentPageChange"
                    layout=" prev, pager, next, total"
                    :total="total_items"
                    :page-count="total_pages">
            </el-pagination>
        </div>
    </el-card>
</div>
<script>
    $(function () {
        new Vue({
            el: "#app",
            data: {
                lists: [],
                total_items: 0,
                total_pages: 0,
                pageCount: 1,
                sort_field: '',// 排序字段
                sort_order: '',// 升序降序
                searchForm: {
                    datetime: ["{:date('Y-m-d')}", "{:date('Y-m-d')}"],
                    file_name: '',
                }
            },
            mounted: function () {
                this.getList()
            },
            methods: {
                search: function () {
                    this.currentPage = 1
                    this.getList()
                },
                currentPageChange: function (e) {
                    this.currentPage = e
                    this.getList()
                },
                getList: function () {
                    var _this = this
                    var data = this.searchForm
                    data['page'] = this.currentPage
                    data['_action'] = 'getList'
                    data['sort_field'] = this.sort_field
                    data['sort_order'] = this.sort_order
                    this.httpGet("{:api_url('/qiniu/admin/files')}", data, function (res) {
                        var data = res.data
                        _this.lists = data.items
                        _this.total_pages = data.total_pages
                        _this.total_items = data.total_items
                    })
                },
                onSortChange: function (event) {
                    this.sort_field = event.prop
                    this.sort_order = event.order === 'ascending' ? 'asc' : 'desc'
                    this.search()
                },
                viewFile: function (file) {
                    window.open(file.file_url)
                },
                changeFileStatus: function (file, file_status) {
                    var that = this
                    let alert_msg = '该操作会使七牛云上的资源设为启用状态，可以直接在公网访问，确定操作？'
                    if(file_status === 1){
                        alert_msg = '该操作会使七牛云上的资源设为禁用状态，只能通过签发 Token 才能访问，确定操作？'
                    }
                    layer.confirm(alert_msg, {title: '提示'}, function (index) {
                        that.doChangeFileStatus(file, file_status)
                        layer.close(index);
                    });
                },
                doChangeFileStatus: function (file, file_status) {
                    var that = this
                    var data = {
                        _action: 'changeFileStatus',
                        uuid: file.uuid,
                        file_status: file_status
                    }
                    this.httpPost("{:api_url('/qiniu/admin/files')}", data, function (res) {
                        layer.msg(res.msg)
                        if (res.status) {
                            that.getList()
                        }
                    })
                },
                deleteFile: function (file) {
                    var that = this
                    layer.confirm('该操作会删除七牛云上的资源且不可逆，确定进行删除？', {title: '提示'}, function (index) {
                        that.doDeleteFile(file)
                        layer.close(index);
                    });
                },
                doDeleteFile: function (file) {
                    var that = this
                    var data = {
                        _action: 'deleteFile',
                        uuid: file.uuid
                    }
                    this.httpPost("{:api_url('/qiniu/admin/files')}", data, function (res) {
                        layer.msg(res.msg)
                        if (res.status) {
                            that.getList()
                        }
                    })
                }
            }
        })
    })
</script>

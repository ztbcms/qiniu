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
                <el-form-item>
                    <el-button
                            type="danger"
                            :loading="batchLoading"
                            :disabled="batchLoading || multipleSelection.length === 0"
                            @click="batchChangeFileStatus(1)">
                        批量禁用
                    </el-button>
                    <el-button
                            type="primary"
                            :loading="batchLoading"
                            :disabled="batchLoading || multipleSelection.length === 0"
                            @click="batchChangeFileStatus(0)">
                        批量启用
                    </el-button>
                    <el-button
                            type="warning"
                            :loading="batchLoading"
                            :disabled="batchLoading || multipleSelection.length === 0"
                            @click="batchDeleteFiles">
                        批量删除
                    </el-button>
                </el-form-item>
            </el-form>
        </div>
        <el-tabs v-model="searchForm.file_status" @tab-click="handleClickFileStatus">
            <el-tab-pane label="全部" name="all"></el-tab-pane>
            <el-tab-pane label="启用" name="0"></el-tab-pane>
            <el-tab-pane label="禁用" name="1"></el-tab-pane>
        </el-tabs>
        <el-table
                ref="filesTable"
                :data="lists"
                style="width: 100%"
                @sort-change="onSortChange"
                @selection-change="handleSelectionChange"
        >
            <el-table-column
                    type="selection"
                    width="55">
            </el-table-column>
            <el-table-column
                    min-width="180"
                    align="left"
                    prop="file_name"
                    label="文件名"
            >
            </el-table-column>
            <el-table-column
                    width="80"
                    align="right"
                    label="文件大小"
            >
                <template slot-scope="scope">
                    {{ autoFormatBytes(scope.row.file_size) }}
                </template>
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
                    :current-page="currentPage"
                    :total="total_items"
                    :page-size="page_size">
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
                page_size: 0,
                pageCount: 1,
                sort_field: '',// 排序字段
                sort_order: '',// 升序降序
                multipleSelection: [],
                batchLoading: false,
                searchForm: {
                    datetime: ["{:date('Y-m-d')}", "{:date('Y-m-d')}"],
                    file_name: '',
                    file_status: 'all',
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
                        _this.page_size = data.limit
                        _this.total_items = data.total_items
                        _this.multipleSelection = []
                        _this.$nextTick(function () {
                            if (_this.$refs.filesTable) {
                                _this.$refs.filesTable.clearSelection()
                            }
                        })
                    })
                },
                handleSelectionChange: function (rows) {
                    this.multipleSelection = rows
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
                    if (file_status === 1) {
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
                },
                batchChangeFileStatus: function (file_status) {
                    if (!this.multipleSelection.length) {
                        layer.msg('请先勾选文件')
                        return
                    }
                    var that = this
                    var rows = this.multipleSelection.slice()
                    var alert_msg = '该操作会使所选七牛云资源设为启用状态，可以直接在公网访问，确定操作？'
                    if (file_status === 1) {
                        alert_msg = '该操作会使所选七牛云资源设为禁用状态，只能通过签发 Token 才能访问，确定操作？'
                    }
                    layer.confirm(alert_msg, {title: '提示'}, function (index) {
                        that.runBatchAction(rows, 'changeFileStatus', file_status)
                        layer.close(index);
                    });
                },
                batchDeleteFiles: function () {
                    if (!this.multipleSelection.length) {
                        layer.msg('请先勾选文件')
                        return
                    }
                    var that = this
                    var rows = this.multipleSelection.slice()
                    layer.confirm('该操作会删除所选七牛云资源且不可逆，确定进行删除？', {title: '提示'}, function (index) {
                        that.runBatchAction(rows, 'deleteFile')
                        layer.close(index);
                    });
                },
                runBatchAction: function (rows, action, file_status) {
                    if (this.batchLoading) {
                        return
                    }
                    if (!rows || !rows.length) {
                        layer.msg('请先勾选文件')
                        return
                    }
                    var that = this
                    var total = rows.length
                    var successCount = 0
                    var failFiles = []
                    var index = 0
                    var loadingStartTime = Date.now()
                    var minLoadingMs = 800
                    var loadingLayerIndex = layer.msg('正在批量处理中，请稍候...', {
                        icon: 16,
                        shade: 0.2,
                        time: 0
                    })
                    this.batchLoading = true

                    var finish = function () {
                        that.batchLoading = false
                        that.getList()
                        var closeLoadingAndShowResult = function () {
                            layer.close(loadingLayerIndex)
                            that.showBatchResult(successCount, total, failFiles)
                        }
                        var elapsed = Date.now() - loadingStartTime
                        if (elapsed < minLoadingMs) {
                            setTimeout(closeLoadingAndShowResult, minLoadingMs - elapsed)
                        } else {
                            closeLoadingAndShowResult()
                        }
                    }

                    var next = function () {
                        if (index >= total) {
                            finish()
                            return
                        }
                        var file = rows[index]
                        index += 1
                        that.postFileAction(file, action, file_status).then(function (res) {
                            if (res && res.status) {
                                successCount += 1
                            } else {
                                failFiles.push(file.file_name || file.uuid)
                            }
                            next()
                        }).catch(function () {
                            failFiles.push(file.file_name || file.uuid)
                            next()
                        })
                    }

                    next()
                },
                postFileAction: function (file, action, file_status) {
                    var data = {
                        _action: action,
                        uuid: file.uuid
                    }
                    if (action === 'changeFileStatus') {
                        data.file_status = file_status
                    }
                    return new Promise(function (resolve, reject) {
                        $.ajax({
                            url: "{:api_url('/qiniu/admin/files')}",
                            type: 'POST',
                            data: data,
                            dataType: 'json',
                            success: function (res) {
                                resolve(res)
                            },
                            error: function () {
                                reject(new Error('request error'))
                            }
                        })
                    })
                },
                showBatchResult: function (successCount, total, failFiles) {
                    var failCount = failFiles.length
                    var summaryHtml = ''
                    summaryHtml += '<div style="display:flex;gap:12px;margin-bottom:12px;">'
                    summaryHtml += '<div style="flex:1;background:#f0f9eb;border:1px solid #e1f3d8;border-radius:6px;padding:10px 12px;">'
                    summaryHtml += '<div style="font-size:12px;color:#67c23a;">成功</div>'
                    summaryHtml += '<div style="font-size:20px;font-weight:600;color:#67c23a;line-height:1.2;">' + successCount + ' / ' + total + '</div>'
                    summaryHtml += '</div>'
                    summaryHtml += '<div style="flex:1;background:#fef0f0;border:1px solid #fde2e2;border-radius:6px;padding:10px 12px;">'
                    summaryHtml += '<div style="font-size:12px;color:#f56c6c;">失败</div>'
                    summaryHtml += '<div style="font-size:20px;font-weight:600;color:#f56c6c;line-height:1.2;">' + failCount + '</div>'
                    summaryHtml += '</div>'
                    summaryHtml += '</div>'

                    var failListHtml = ''
                    if (failCount > 0) {
                        failListHtml += '<div style="font-size:14px;font-weight:600;color:#303133;margin-bottom:8px;">失败文件(' + failCount + ' 个)</div>'
                        failListHtml += '<div style="max-height:260px;overflow:auto;border:1px solid #ebeef5;border-radius:6px;padding:8px 12px;background:#fff;">'
                        failListHtml += '<ol style="margin:0;padding-left:18px;color:#606266;line-height:1.7;">'
                        for (var i = 0; i < failFiles.length; i++) {
                            failListHtml += '<li style="word-break:break-all;">' + this.escapeHtml(failFiles[i]) + '</li>'
                        }
                        failListHtml += '</ol>'
                        failListHtml += '</div>'
                    } else {
                        failListHtml = '<div style="padding:14px;border:1px solid #e1f3d8;border-radius:6px;background:#f0f9eb;color:#67c23a;">全部处理成功，没有失败文件。</div>'
                    }

                    layer.open({
                        type: 1,
                        title: '操作结果',
                        area: ['620px', '520px'],
                        shadeClose: false,
                        content: '<div style="padding:16px 18px;background:#fafafa;">' + summaryHtml + failListHtml + '</div>'
                    })
                },
                escapeHtml: function (text) {
                    if (text === undefined || text === null) {
                        return ''
                    }
                    return String(text)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;')
                },
                autoFormatBytes: function (val) {
                    if (val < 1024) {
                        return val + 'B'
                    } else if (val < 1024 * 1024) {
                        return Math.ceil(val / 1024) + 'KB'
                    } else {
                        return Math.ceil(val / 1024 / 1024) + 'MB'
                    }
                },
                // 文件状态 tab 点击
                handleClickFileStatus: function (tab) {
                    this.search()
                },
            }
        })
    })
</script>

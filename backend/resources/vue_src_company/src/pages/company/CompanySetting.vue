<template>
  <section class="cs-card">
    <h2 class="cs-title"><i class="fa-solid fa-building" aria-hidden="true"></i>{{ companyLabel() || '本公司' }}</h2>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon class="cs-alert" />
    <div class="cs-row">
      <div>
        <div class="cs-label">員工新增訪客預約需要審核</div>
        <p class="cs-desc">
          開啟：員工新增或修改的預約為「待審核」，由公司管理員或審核者審核後才生效。<br />
          關閉：員工新增的預約直接「已核准」，修改後直接「修改已核准」，不需要審核。
        </p>
        <p class="cs-desc cs-desc--note">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          這個開關只影響本公司員工從公司端口建立的預約；其他來源的訪客申請（例如大樓管理處代為登記）一律需要審核，
          會出現在「訪客審核」由公司管理員或審核者處理。設定只影響之後新增或修改的預約，已在待審核的預約仍需審核。
        </p>
      </div>
      <el-switch
        v-model="approvalRequired"
        :loading="saving"
        :disabled="!company.loaded"
        inline-prompt
        active-text="需要"
        inactive-text="免審核"
        :before-change="confirmChange"
      />
    </div>
  </section>
</template>

<script setup>
/* *****************************************
File: CompanySetting.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口「審核設定」：公司管理員設定員工新增訪客預約是否需要審核。
***************************************** */
import { onMounted, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { saveApprovalSetting } from '../../api/company';
import { applyCompany, company, companyLabel, loadCompany } from '../../stores/company';

const approvalRequired = ref(true);
const saving = ref(false);
const loadError = ref('');

// el-switch 的 before-change：先確認、送出，成功才切換（回傳 false 就維持原狀）。
async function confirmChange(){
  const next = !approvalRequired.value;
  try{
    await ElMessageBox.confirm(
      next ? '開啟後，員工新增的預約需要公司管理員或審核者審核。確定開啟嗎？' : '關閉後，員工新增的預約會直接核准，不再經過審核。確定關閉嗎？',
      '變更審核設定',
      { type: 'warning', confirmButtonText: '確定', cancelButtonText: '取消' },
    );
  }catch{
    return false;
  }
  saving.value = true;
  const result = await saveApprovalSetting(next);
  saving.value = false;
  if(!result.ok){
    ElMessage.error(`儲存失敗：${result.message}`);
    return false;
  }
  applyCompany(result.data);
  ElMessage.success(next ? '已開啟審核' : '已改為免審核');
  return true;
}

onMounted(async () => {
  const result = await loadCompany(true);
  if(!result.ok){
    loadError.value = `本公司資訊載入失敗：${result.message}`;
    return;
  }
  approvalRequired.value = company.approvalRequired;
});
</script>

<style scoped>
.cs-card{
  max-width: 860px;
  padding: 24px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(15, 35, 72, 0.06);
}
.cs-title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0 0 20px;
  font-size: 18px;
}
.cs-alert{
  margin-bottom: 16px;
}
.cs-row{
  display: flex;
  gap: 24px;
  align-items: flex-start;
  justify-content: space-between;
  padding: 16px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.cs-label{
  font-weight: 600;
}
.cs-desc{
  margin: 8px 0 0;
  color: #4b5563;
  font-size: 14px;
  line-height: 1.7;
}
.cs-desc--note{
  color: #6b7280;
  font-size: 13px;
}
</style>
